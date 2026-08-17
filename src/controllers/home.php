<?php
/**
 * Public library: the browse/search page, its JSON endpoints, and the asset modal payload.
 */
declare(strict_types=1);

/** Read filter state from the query string. Shape is shared by the page and the JSON API. */
function request_filters(): array
{
    $q = trim((string) ($_GET['q'] ?? ''));

    $raw   = (string) ($_GET['c'] ?? '');
    $slugs = array_values(array_filter(array_map(
        static fn(string $s): string => trim($s),
        $raw === '' ? [] : explode(',', $raw)
    )));

    return ['q' => mb_substr($q, 0, 200), 'categories' => $slugs];
}

/**
 * The library page.
 *
 * Rendered server-side from the URL so a shared or bookmarked link shows the same results
 * without waiting on JavaScript; the client script then takes over for subsequent
 * interactions and keeps the URL in step.
 */
function home_index(?string $openSlug = null): never
{
    $filters  = request_filters();
    $taxonomy = taxonomy_all(true);
    $assets   = assets_search($filters);

    // A deep link to an asset that's gone (or was never published) should still show the
    // library rather than a dead end — the modal simply doesn't open.
    $openAsset = $openSlug ? asset_find($openSlug) : null;

    $pageTitle = $openAsset['title'] ?? 'Design Asset Library';
    require APP_ROOT . '/views/home.php';
    exit;
}

/** Grid data for the live search/filter interactions. */
function api_assets(): never
{
    $filters = request_filters();
    $assets  = assets_search($filters);

    json_out([
        'total'  => count($assets),
        'assets' => array_map(static fn(array $a): array => [
            'id'        => $a['id'],
            'title'     => $a['title'],
            'slug'      => $a['slug'],
            'summary'   => $a['summary'],
            'thumb_url' => $a['thumb_url'],
        ], $assets),
    ]);
}

/** Everything the modal needs for one asset. */
function api_asset(string $slug): never
{
    $asset = asset_find($slug);
    if (!$asset) {
        json_out(['error' => 'Asset not found'], 404);
    }
    json_out(asset_modal_payload($asset));
}

/**
 * Shape an asset row for the modal. Kept in one place so the server-rendered deep link and
 * the JSON endpoint can never drift apart.
 */
function asset_modal_payload(array $asset): array
{
    return [
        'id'        => $asset['id'],
        'title'     => $asset['title'],
        'slug'      => $asset['slug'],
        'summary'   => $asset['summary'],
        'body_html' => $asset['body_html'] ?? '',
        'url'       => url('/asset/' . $asset['slug']),
        'images'    => array_map(static fn(array $i): array => [
            'url'       => $i['url'],
            'thumb_url' => $i['thumb_url'],
            'alt'       => $i['orig_name'],
        ], $asset['images']),
        'ctas' => array_map(static fn(array $c): array => [
            'label' => $c['label'],
            'url'   => $c['url'],
            'style' => $c['style'],
        ], $asset['ctas']),
        'categories' => array_map(static fn(array $c): array => [
            'name'  => $c['name'],
            'slug'  => $c['slug'],
            'group' => $c['group_name'],
        ], $asset['categories']),
    ];
}
