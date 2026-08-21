<?php
/**
 * Boot: environment, database handle, session, and the shared includes.
 *
 * Included by index.php before anything else. Everything here is side-effect-safe to include
 * once per request.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/**
 * Public URL prefix.
 *
 * nginx strips /asset-library before proxying, so PHP never sees it in REQUEST_URI — but the
 * browser still needs it on every link, form action and asset src. Overridable via the
 * environment for the (rare) case of serving this app at a different mount point.
 */
define('BASE_PATH', rtrim(getenv('APP_BASE_PATH') ?: '/asset-library', '/'));

/** Where uploads live. A rw volume is mounted here; the rest of the tree is read-only. */
define('UPLOAD_DIR', APP_ROOT . '/data/uploads');
define('UPLOAD_URL', BASE_PATH . '/data/uploads');

require APP_ROOT . '/src/errors.php';
require APP_ROOT . '/src/version.php';
require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/auth.php';
require APP_ROOT . '/src/images.php';
require APP_ROOT . '/src/schema.php';
require APP_ROOT . '/src/repo.php';

// Installed before the database or session so a failure in either is reported properly
// rather than surfacing as a blank page.
errors_install();

/**
 * Parse the gitignored .env (KEY=VALUE, # comments). Kept deliberately tiny — this app has no
 * composer dependencies and the file is ours, not user input.
 */
function env_load(string $path): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!is_readable($path)) {
        return $cache;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $cache[trim($k)] = trim(trim($v), "\"'");
    }
    return $cache;
}

function env(string $key, ?string $default = null): ?string
{
    $vals = env_load(APP_ROOT . '/.env');
    return $vals[$key] ?? $default;
}

/** Shared PDO handle. Connects over the MySQL unix socket (this host serves no TCP MySQL). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $socket = env('DB_SOCKET', '/var/run/mysqld/mysqld.sock');
    $name   = env('DB_NAME', 'asset-library');
    $dsn    = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name);

    try {
        $pdo = new PDO($dsn, env('DB_USER', ''), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Never leak the DSN or credentials to the browser.
        error_log('asset-library: DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit('The asset library is temporarily unavailable (database).');
    }

    return $pdo;
}

// Sessions back the CSRF token. /tmp is writable in the container; the code mount is not.
//
// The Secure flag tracks the real scheme rather than being hard-coded: nginx terminates TLS
// and forwards X-Forwarded-Proto, so this is "on" in production — but hard-coding it would
// make the cookie undeliverable over plain HTTP against the container directly, which is how
// the app is smoke-tested before it is behind the edge.
$isHttps = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        // Scoped to this app so the sibling apps on design.poh-apps.com never receive it.
        'path'     => BASE_PATH ?: '/',
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_name('asset_library');
    session_start();
}

// The edge (oauth2-proxy) authenticates every request; a miss means the app is exposed
// somewhere it should not be, so fail closed rather than serving anonymously.
if (!poh_is_authenticated()) {
    http_response_code(401);
    exit('Not authenticated.');
}

/**
 * Catch a POST that PHP silently discarded for exceeding post_max_size.
 *
 * When the body is too big PHP empties $_POST and $_FILES but still runs the request, so the
 * next thing to fail is the CSRF check — which would tell the user their session expired and
 * send them off clearing cookies instead of shrinking their upload.
 */
$ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
// PHP only populates $_POST for these two content types, so an empty $_POST means nothing at
// all for a JSON body — checking without this would reject every JSON request as oversized.
$phpParsesBody = str_contains($ctype, 'multipart/form-data')
    || str_contains($ctype, 'application/x-www-form-urlencoded');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && $phpParsesBody
    && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    $limit = ini_get('post_max_size');
    error_log(sprintf(
        'asset-library: POST to %s discarded — %s bytes exceeds post_max_size=%s',
        $_SERVER['REQUEST_URI'] ?? '?', $_SERVER['CONTENT_LENGTH'], $limit
    ));
    // The full status line, not http_response_code(): when PHP has discarded an oversized
    // body, mod_php does not reliably carry the bare code through and the response goes out
    // as 200 — a failure reported as success.
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 413 Payload Too Large', true, 413);
    header('Content-Type: text/plain; charset=utf-8');
    header('Connection: close');
    exit(
        "That upload was too large to accept (the limit is {$limit} for the whole form).\n\n"
        . "Go back, remove or shrink some images, and save again. Each image can be up to 5 MB."
    );
}

schema_migrate();
