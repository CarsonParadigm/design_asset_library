<?php
/**
 * Small shared helpers: URLs, escaping, slugs, CSRF, and HTML sanitising.
 */
declare(strict_types=1);

/** Build a browser-facing URL. Always use this — see BASE_PATH in bootstrap.php for why. */
function url(string $path = '/', array $query = []): string
{
    $u = BASE_PATH . '/' . ltrim($path, '/');
    $u = rtrim($u, '/');
    if ($u === '') {
        $u = BASE_PATH ?: '/';
    }
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

/** Escape for HTML text/attribute context. */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 302);
    exit;
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * URL-safe slug. Falls back to a random token so a title of only punctuation (or a
 * non-Latin script that transliterates to nothing) can never produce an empty slug.
 */
function slugify(string $text): string
{
    $s = trim($text);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) {
            $s = $t;
        }
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 80) : 'item-' . bin2hex(random_bytes(3));
}

/**
 * Make $slug unique within $table.$column, ignoring row $ignoreId (so saving an unchanged
 * record doesn't bump its own slug). Table/column are literals from our own call sites.
 */
function unique_slug(string $table, string $slug, ?int $ignoreId = null, string $column = 'slug'): string
{
    $base = $slug;
    $n    = 1;
    while (true) {
        $sql    = "SELECT id FROM `$table` WHERE `$column` = ?";
        $params = [$slug];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }
        $st = db()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if (!$st->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . (++$n);
    }
}

/* ── CSRF ─────────────────────────────────────────────────────────────────────────────── */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the token on every state-changing request; abort the request if it doesn't match.
 *
 * Note the explicit empty checks: hash_equals('', '') is true, so without them a request that
 * sent no token would be accepted whenever the session had not yet issued one.
 *
 * Responds 403 rather than the 419 ("page expired") some frameworks use — Apache does not
 * recognise 419 and rewrites it to a 500, which reads as a server fault instead of a rejected
 * request.
 */
function csrf_check(): void
{
    $expected = $_SESSION['csrf'] ?? '';
    $sent     = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (is_string($sent) && $sent !== '' && is_string($expected) && $expected !== ''
        && hash_equals($expected, $sent)) {
        return;
    }

    $message = 'Your session expired. Go back, reload the page and try again.';

    // Answer in the format the caller asked for, so a fetch() sees a parseable error rather
    // than an HTML page that blows up its .json() call.
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        json_out(['ok' => false, 'error' => $message], 403);
    }

    http_response_code(403);
    exit($message);
}

/* ── Rich text ────────────────────────────────────────────────────────────────────────── */

/**
 * Sanitise WYSIWYG HTML before storing it.
 *
 * Only admins (currently one person) can reach the editor, so this guards against a mistake
 * or a pasted payload rather than a hostile author — but the output is rendered for every
 * employee, so it still gets filtered rather than trusted.
 *
 * Allows Quill's formatting output plus <iframe> for video embeds, with iframe src limited to
 * an allowlist of video hosts.
 */
function sanitize_rich_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $allowedTags = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'img', 'iframe',
        'span', 'div', 'hr', 'sub', 'sup', 'table', 'thead', 'tbody', 'tr', 'td', 'th',
    ];
    $allowedAttrs = [
        'a'      => ['href', 'title', 'target', 'rel'],
        'img'    => ['src', 'alt', 'title', 'width', 'height'],
        'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'title', 'frameborder'],
        '*'      => ['class'],
    ];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // The meta line pins UTF-8; without it DOMDocument assumes Latin-1 and mangles accents.
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div id="__root">'
            . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $root = $doc->getElementById('__root');
    if (!$root) {
        return '';
    }

    // Walk a static list: the tree is mutated while iterating.
    $all = iterator_to_array($doc->getElementsByTagName('*'), false);
    foreach ($all as $el) {
        if (!$el instanceof DOMElement || $el === $root) {
            continue;
        }
        $tag = strtolower($el->nodeName);

        if (!in_array($tag, $allowedTags, true)) {
            // Unwrap unknown tags rather than dropping their text — except <script>/<style>,
            // whose contents are code, not content.
            if (in_array($tag, ['script', 'style'], true)) {
                $el->parentNode?->removeChild($el);
            } else {
                while ($el->firstChild) {
                    $el->parentNode?->insertBefore($el->firstChild, $el);
                }
                $el->parentNode?->removeChild($el);
            }
            continue;
        }

        $permitted = array_merge($allowedAttrs[$tag] ?? [], $allowedAttrs['*']);
        foreach (iterator_to_array($el->attributes ?? [], false) as $attr) {
            $name = strtolower($attr->nodeName);
            if (!in_array($name, $permitted, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }
            // Block javascript:/data: URLs in any URL-bearing attribute.
            if (in_array($name, ['href', 'src'], true) && !is_safe_url($attr->nodeValue ?? '', $tag)) {
                $el->removeAttribute($attr->nodeName);
            }
        }

        if ($tag === 'a' && $el->getAttribute('href') !== '') {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
        if ($tag === 'iframe') {
            if ($el->getAttribute('src') === '') {
                $el->parentNode?->removeChild($el);
                continue;
            }
            $el->setAttribute('allowfullscreen', 'true');
            $el->setAttribute('loading', 'lazy');
        }
    }

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

/**
 * Is this URL safe to emit? http/https (and protocol-relative) only; iframes are further
 * restricted to known video hosts so an embed can't become an arbitrary framed page.
 */
function is_safe_url(string $url, string $context = 'a'): bool
{
    $u = trim($url);
    if ($u === '') {
        return false;
    }
    // Reject control characters used to smuggle "java\nscript:".
    if (preg_match('/[\x00-\x1F\x7F]/', $u)) {
        return false;
    }
    if (str_starts_with($u, '//')) {
        $u = 'https:' . $u;
    }
    if (!preg_match('#^https?://#i', $u)) {
        // Allow same-app relative paths for <img>/<a>, but never for <iframe>.
        return $context !== 'iframe' && str_starts_with($u, '/');
    }
    if ($context === 'iframe') {
        $host = strtolower((string) parse_url($u, PHP_URL_HOST));
        foreach (VIDEO_EMBED_HOSTS as $ok) {
            if ($host === $ok || str_ends_with($host, '.' . $ok)) {
                return true;
            }
        }
        return false;
    }
    return true;
}

/** Hosts permitted in a WYSIWYG <iframe> embed. */
const VIDEO_EMBED_HOSTS = [
    'youtube.com',
    'youtube-nocookie.com',
    'vimeo.com',
    'player.vimeo.com',
    'loom.com',
    'wistia.com',
    'wistia.net',
    'canva.com',
    'microsoftstream.com',
    'sharepoint.com',
];

/** Plain-text excerpt of rich HTML, for search indexing and card summaries. */
function html_to_text(string $html): string
{
    $t = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t) ?? '');
}

function str_excerpt(string $text, int $len = 160): string
{
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $len), " \t\n\r\0\x0B.,;:") . '…';
}
