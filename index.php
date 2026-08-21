<?php
/**
 * Front controller.
 *
 * .htaccess sends every non-file request here. Remember that nginx strips the /asset-library
 * prefix before proxying, so the paths matched below are un-prefixed; url() puts the prefix
 * back on anything the browser will see.
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$path   = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isPost = $method === 'POST';

/**
 * Match $pattern against the path, capturing {name} segments into $params.
 * preg_quote escapes the braces, so the placeholder pattern below matches them escaped.
 */
function route(string $pattern, string $path, ?array &$params = null): bool
{
    $regex = '#^' . preg_replace('/\\\\\{(\w+)\\\\\}/', '(?P<$1>[^/]+)', preg_quote($pattern, '#')) . '$#';
    if (!preg_match($regex, $path, $m)) {
        return false;
    }
    $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    return true;
}

$p = [];

try {
    /* ── Public ───────────────────────────────────────────────────────────────────────── */

    if ($path === '/' && !$isPost) {
        require APP_ROOT . '/src/controllers/home.php';
        home_index();
    }

    // Deep link to a single asset: renders the library with that asset's modal already open,
    // so a shared URL lands somewhere useful instead of on a bare grid.
    if (route('/asset/{slug}', $path, $p) && !$isPost) {
        require APP_ROOT . '/src/controllers/home.php';
        home_index($p['slug']);
    }

    if ($path === '/api/assets' && !$isPost) {
        require APP_ROOT . '/src/controllers/home.php';
        api_assets();
    }

    if (route('/api/asset/{slug}', $path, $p) && !$isPost) {
        require APP_ROOT . '/src/controllers/home.php';
        api_asset($p['slug']);
    }

    /**
     * Receive a JavaScript error from the browser.
     *
     * Front-end failures are otherwise invisible — they happen on someone else's machine and
     * never reach the server. During the soft launch they go into the same log as everything
     * else, so a report of "it just did nothing" has evidence behind it.
     */
    if ($path === '/api/client-error' && $isPost) {
        csrf_check();
        $payload = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
        error_log(sprintf(
            'asset-library[client] %s | %s | at %s:%s:%s | page %s | %s',
            substr((string) ($payload['kind'] ?? 'error'), 0, 40),
            substr((string) ($payload['message'] ?? ''), 0, 500),
            substr((string) ($payload['source'] ?? '?'), 0, 200),
            (int) ($payload['line'] ?? 0),
            (int) ($payload['column'] ?? 0),
            substr((string) ($payload['page'] ?? ''), 0, 200),
            substr((string) ($payload['agent'] ?? ''), 0, 160)
        ));
        json_out(['ok' => true]);
    }

    /**
     * Toggle the "view as user" preview.
     *
     * Deliberately outside the /admin gate: turning the preview ON makes require_admin() start
     * refusing, so a route under /admin could be entered but never left.
     */
    if ($path === '/view-as' && $isPost) {
        csrf_check();
        if (!is_admin()) {
            http_response_code(403);
            exit('Not permitted.');
        }
        $toUser = ($_POST['mode'] ?? '') === 'user';
        set_view_as_user($toUser);

        // Only ever return to a path inside this app, and never back into /admin while the
        // preview is on — that would land on a 403 immediately after enabling it.
        $raw   = (string) ($_POST['return'] ?? '/');
        $parts = parse_url($raw);
        $return = '/';
        if ($parts !== false
            && empty($parts['host']) && empty($parts['scheme'])   // reject absolute URLs outright
            && !str_starts_with($raw, '//')                       // and protocol-relative ones
        ) {
            $candidate = '/' . ltrim($parts['path'] ?? '/', '/');
            if (!str_starts_with($candidate, '/admin')) {
                $return = $candidate;
            }
        }
        redirect(url($return));
    }

    /* ── Admin ────────────────────────────────────────────────────────────────────────── */

    if (str_starts_with($path, '/admin')) {
        require_admin();
        require APP_ROOT . '/src/controllers/admin.php';

        if ($path === '/admin' || $path === '/admin/') {
            redirect(url('/admin/assets'));
        }

        // Assets
        if ($path === '/admin/assets' && !$isPost)            { admin_assets_index(); }
        if ($path === '/admin/assets/new' && !$isPost)        { admin_asset_form(null); }
        if ($path === '/admin/assets/create' && $isPost)      { admin_asset_save(null); }
        if (route('/admin/assets/{id}/edit', $path, $p) && !$isPost)   { admin_asset_form((int) $p['id']); }
        if (route('/admin/assets/{id}/save', $path, $p) && $isPost)    { admin_asset_save((int) $p['id']); }
        if (route('/admin/assets/{id}/delete', $path, $p) && $isPost)  { admin_asset_delete((int) $p['id']); }

        // Taxonomy
        if ($path === '/admin/filters' && !$isPost)                    { admin_filters_index(); }
        if ($path === '/admin/filters/group/save' && $isPost)          { admin_group_save(); }
        if (route('/admin/filters/group/{id}/delete', $path, $p) && $isPost)    { admin_group_delete((int) $p['id']); }
        if ($path === '/admin/filters/category/save' && $isPost)        { admin_category_save(); }
        if ($path === '/admin/filters/category/reorder' && $isPost)     { admin_category_reorder(); }
        if (route('/admin/filters/category/{id}/delete', $path, $p) && $isPost) { admin_category_delete((int) $p['id']); }
    }

    /* ── Not found ────────────────────────────────────────────────────────────────────── */

    http_response_code(404);
    $pageTitle = 'Not found';
    require APP_ROOT . '/views/404.php';
} catch (Throwable $e) {
    // One reporter for everything: it logs the full detail with a reference code and renders
    // either HTML or JSON, at the detail level the viewer is entitled to.
    render_error($e);
}
