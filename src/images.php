<?php
/**
 * Image intake: validation, GD derivative generation, and pluggable storage.
 *
 * Every uploaded file is re-encoded through GD rather than stored as received. That is both a
 * size control (a design export can be enormous) and a safety one: re-encoding discards EXIF,
 * embedded payloads and anything that is not actually pixels, so a "polyglot" file cannot
 * survive the trip.
 *
 * Two derivatives are written per image:
 *   {uuid}.{ext}    display copy, longest side <= DISPLAY_MAX px  (asset modal)
 *   {uuid}_t.{ext}  thumbnail,    longest side <= THUMB_MAX px    (grid + gallery strip)
 */
declare(strict_types=1);

/** Per-file ceiling. Keep in step with upload_max_filesize in the Containerfile. */
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

/** Refuse absurd dimensions before decoding — GD allocates ~4 bytes per pixel. */
const MAX_INPUT_PIXELS = 50_000_000;

const DISPLAY_MAX = 2400;
const THUMB_MAX   = 600;

/** Max gallery images per asset, on top of the featured image. */
const MAX_GALLERY_IMAGES = 10;

/** Thrown for anything the user can fix by choosing a different file. */
class ImageUploadError extends RuntimeException {}

/* ── Storage backends ─────────────────────────────────────────────────────────────────── */

/**
 * Where generated image files live.
 *
 * Implementations must be interchangeable: the database only ever records the relative path
 * (e.g. "2026/08/ab12….jpg"), never an absolute URL, so the backend can be switched by
 * changing IMAGE_STORE in .env without touching stored rows.
 */
interface ImageStore
{
    /** Persist $tmpPath under $relPath. Must overwrite silently if $relPath already exists. */
    public function store(string $tmpPath, string $relPath, string $mime): void;

    /** Remove $relPath. Missing files are not an error. */
    public function delete(string $relPath): void;

    /** Public URL for $relPath. */
    public function url(string $relPath): string;
}

/** Files on the app's rw volume, served straight off disk by Apache. */
final class LocalImageStore implements ImageStore
{
    public function store(string $tmpPath, string $relPath, string $mime): void
    {
        $dest = UPLOAD_DIR . '/' . $relPath;
        $dir  = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create upload directory: $dir");
        }
        if (!@copy($tmpPath, $dest)) {
            throw new RuntimeException("Cannot write upload to $dest");
        }
        @chmod($dest, 0664);
    }

    public function delete(string $relPath): void
    {
        @unlink(UPLOAD_DIR . '/' . $relPath);
    }

    public function url(string $relPath): string
    {
        return UPLOAD_URL . '/' . $relPath;
    }
}

/**
 * Bunny.net Edge Storage, delivered through a public Pull Zone.
 *
 * Note the delivery URLs are public: anyone holding one can fetch the image without passing
 * the M365 edge. Paths are uuid-based so they cannot be enumerated, but that is obscurity,
 * not access control — chosen deliberately (2026-08-17). Switch IMAGE_STORE back to "local",
 * or add Bunny Token Authentication, if assets ever need to be genuinely private.
 */
final class BunnyImageStore implements ImageStore
{
    public function __construct(
        private string $zone,
        private string $accessKey,
        private string $storageHost,
        private string $pullZoneHost,
    ) {
        if ($this->zone === '' || $this->accessKey === '' || $this->pullZoneHost === '') {
            throw new RuntimeException(
                'Bunny storage is selected but BUNNY_STORAGE_ZONE, BUNNY_STORAGE_KEY or '
                . 'BUNNY_PULL_ZONE_HOST is missing from .env'
            );
        }
    }

    public function store(string $tmpPath, string $relPath, string $mime): void
    {
        $fh = fopen($tmpPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot read $tmpPath for upload");
        }
        try {
            [$status, $body] = $this->request('PUT', $relPath, [
                CURLOPT_UPLOAD     => true,
                CURLOPT_INFILE     => $fh,
                CURLOPT_INFILESIZE => filesize($tmpPath),
                CURLOPT_HTTPHEADER => [
                    'AccessKey: ' . $this->accessKey,
                    'Content-Type: ' . $mime,
                ],
            ]);
        } finally {
            fclose($fh);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Bunny upload failed for $relPath (HTTP $status): $body");
        }
    }

    public function delete(string $relPath): void
    {
        // 404 is fine — the file is gone either way, which is all the caller wanted.
        [$status, $body] = $this->request('DELETE', $relPath, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER    => ['AccessKey: ' . $this->accessKey],
        ]);
        if ($status >= 400 && $status !== 404) {
            error_log("asset-library: Bunny delete failed for $relPath (HTTP $status): $body");
        }
    }

    public function url(string $relPath): string
    {
        return 'https://' . $this->pullZoneHost . '/' . $relPath;
    }

    /** @return array{0:int,1:string} [status, body] */
    private function request(string $method, string $relPath, array $opts): array
    {
        $url = sprintf('https://%s/%s/%s', $this->storageHost, rawurlencode($this->zone), $relPath);
        $ch  = curl_init($url);
        curl_setopt_array($ch, $opts + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FAILONERROR    => false,
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("Bunny $method failed for $relPath: $err");
        }
        return [$status, is_string($body) ? substr($body, 0, 500) : ''];
    }
}

/** The configured store, built once per request. */
function image_store(): ImageStore
{
    static $store = null;
    if ($store instanceof ImageStore) {
        return $store;
    }

    if (strtolower((string) env('IMAGE_STORE', 'local')) === 'bunny') {
        $store = new BunnyImageStore(
            (string) env('BUNNY_STORAGE_ZONE', ''),
            (string) env('BUNNY_STORAGE_KEY', ''),
            (string) env('BUNNY_STORAGE_HOST', 'storage.bunnycdn.com'),
            (string) env('BUNNY_PULL_ZONE_HOST', ''),
        );
    } else {
        $store = new LocalImageStore();
    }
    return $store;
}

/** Public URL for a stored relative path. */
function image_url(?string $relPath): ?string
{
    return $relPath ? image_store()->url($relPath) : null;
}

/** Thumbnail path that pairs with a display path ("a/b.jpg" -> "a/b_t.jpg"). */
function thumb_path(string $relPath): string
{
    $ext = pathinfo($relPath, PATHINFO_EXTENSION);
    return substr($relPath, 0, -(strlen($ext) + 1)) . '_t.' . $ext;
}

/* ── Intake ───────────────────────────────────────────────────────────────────────────── */

/**
 * Validate one $_FILES entry, generate derivatives and store them.
 *
 * @return array{path:string,width:int,height:int,mime:string,orig_name:string}
 * @throws ImageUploadError on anything the user can correct.
 */
function image_ingest(array $file): array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        throw new ImageUploadError(upload_error_message($err, $file['name'] ?? 'file'));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new ImageUploadError('That upload could not be read. Please try again.');
    }
    if (($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        throw new ImageUploadError(sprintf(
            '“%s” is %s — the limit is %s per image.',
            $file['name'] ?? 'That file',
            format_bytes((int) $file['size']),
            format_bytes(MAX_UPLOAD_BYTES)
        ));
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new ImageUploadError(sprintf(
            '“%s” is not an image we can read. Use JPEG, PNG, WebP or GIF.',
            $file['name'] ?? 'That file'
        ));
    }
    [$w, $h] = $info;
    $type    = $info[2];

    if ($w * $h > MAX_INPUT_PIXELS) {
        throw new ImageUploadError(sprintf(
            '“%s” is %d×%d pixels, which is too large to process. Please downscale it first.',
            $file['name'] ?? 'That file', $w, $h
        ));
    }

    $src = load_image($file['tmp_name'], $type);
    if ($src === null) {
        throw new ImageUploadError('That image format is not supported. Use JPEG, PNG, WebP or GIF.');
    }

    // GIF and any palette image become PNG so transparency survives resizing; everything else
    // keeps its own format so photos stay JPEG and flat graphics stay lossless.
    $outType = match ($type) {
        IMAGETYPE_JPEG => IMAGETYPE_JPEG,
        IMAGETYPE_WEBP => IMAGETYPE_WEBP,
        default        => IMAGETYPE_PNG,
    };
    $ext = match ($outType) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        default        => 'png',
    };
    $mime = image_type_to_mime_type($outType);

    $uuid    = uuid4();
    $relDir  = date('Y/m');
    $relPath = "$relDir/$uuid.$ext";

    $tmpDir = sys_get_temp_dir() . '/asset-library-' . $uuid;
    if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        imagedestroy($src);
        throw new RuntimeException('Cannot create a temporary directory for image processing.');
    }

    try {
        $display = resize_within($src, DISPLAY_MAX);
        $dW      = imagesx($display);
        $dH      = imagesy($display);
        $dFile   = "$tmpDir/display.$ext";
        write_image($display, $dFile, $outType);
        if ($display !== $src) {
            imagedestroy($display);
        }

        $thumb  = resize_within($src, THUMB_MAX);
        $tFile  = "$tmpDir/thumb.$ext";
        write_image($thumb, $tFile, $outType);
        if ($thumb !== $src) {
            imagedestroy($thumb);
        }

        $store = image_store();
        $store->store($dFile, $relPath, $mime);
        try {
            $store->store($tFile, thumb_path($relPath), $mime);
        } catch (Throwable $e) {
            // Don't leave a display image with no thumbnail behind.
            $store->delete($relPath);
            throw $e;
        }
    } finally {
        imagedestroy($src);
        foreach (glob("$tmpDir/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);
    }

    return [
        'path'      => $relPath,
        'width'     => $dW,
        'height'    => $dH,
        'mime'      => $mime,
        'orig_name' => substr((string) ($file['name'] ?? 'image'), 0, 255),
    ];
}

/** Delete a stored image and its thumbnail. */
function image_forget(string $relPath): void
{
    $store = image_store();
    $store->delete($relPath);
    $store->delete(thumb_path($relPath));
}

/* ── GD plumbing ──────────────────────────────────────────────────────────────────────── */

function load_image(string $path, int $type): ?GdImage
{
    $img = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        IMAGETYPE_GIF  => @imagecreatefromgif($path),
        default        => false,
    };
    if (!$img instanceof GdImage) {
        return null;
    }
    // Cameras and phones record orientation in EXIF rather than rotating the pixels.
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $deg  = match ((int) ($exif['Orientation'] ?? 1)) {
            3       => 180,
            6       => -90,
            8       => 90,
            default => 0,
        };
        if ($deg !== 0) {
            $rotated = @imagerotate($img, $deg, 0);
            if ($rotated instanceof GdImage) {
                imagedestroy($img);
                $img = $rotated;
            }
        }
    }
    return $img;
}

/** Scale so the longest side is at most $max. Returns $src unchanged if it already fits. */
function resize_within(GdImage $src, int $max): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= $max && $h <= $max) {
        return $src;
    }
    $scale = $max / max($w, $h);
    $nw    = max(1, (int) round($w * $scale));
    $nh    = max(1, (int) round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    // Preserve alpha rather than compositing onto black.
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

function write_image(GdImage $img, string $path, int $type): void
{
    $ok = match ($type) {
        // JPEG has no alpha: flatten onto white so transparent PNGs converted to JPEG don't
        // come out with black fringing.
        IMAGETYPE_JPEG => imagejpeg(flatten_onto_white($img), $path, 88),
        IMAGETYPE_WEBP => imagewebp($img, $path, 88),
        default        => imagepng($img, $path, 6),
    };
    if (!$ok) {
        throw new RuntimeException("Failed to encode image to $path");
    }
}

function flatten_onto_white(GdImage $img): GdImage
{
    $w   = imagesx($img);
    $h   = imagesy($img);
    $out = imagecreatetruecolor($w, $h);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagealphablending($out, true);
    imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
    return $out;
}

/* ── Small utilities ──────────────────────────────────────────────────────────────────── */

function uuid4(): string
{
    $b    = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' bytes';
}

function upload_error_message(int $code, string $name): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
            '“%s” is larger than the %s limit per image.', $name, format_bytes(MAX_UPLOAD_BYTES)
        ),
        UPLOAD_ERR_PARTIAL   => sprintf('“%s” only uploaded partially. Please try again.', $name),
        UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR,
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the upload. Please tell an administrator.',
        UPLOAD_ERR_EXTENSION => 'That upload was blocked by the server.',
        default              => sprintf('“%s” could not be uploaded.', $name),
    };
}

/**
 * Normalise PHP's $_FILES shape for <input type="file" multiple> into a plain list of
 * per-file arrays, which is far easier to loop over than the transposed original.
 *
 * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function normalize_files(array $field): array
{
    if (!isset($field['name'])) {
        return [];
    }
    if (!is_array($field['name'])) {
        return $field['error'] === UPLOAD_ERR_NO_FILE ? [] : [$field];
    }
    $out = [];
    foreach (array_keys($field['name']) as $i) {
        if (($field['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name'     => $field['name'][$i],
            'type'     => $field['type'][$i] ?? '',
            'tmp_name' => $field['tmp_name'][$i] ?? '',
            'error'    => $field['error'][$i],
            'size'     => $field['size'][$i] ?? 0,
        ];
    }
    return $out;
}
