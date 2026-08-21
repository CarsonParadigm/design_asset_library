<?php
/**
 * The library page: filter sidebar, search, asset grid, and the (initially empty) modal.
 *
 * Expects: $taxonomy, $assets, $filters, $openAsset, $pageTitle.
 */
declare(strict_types=1);

require_once APP_ROOT . '/views/layout.php';

$selected  = array_flip($filters['categories']);
$hasFilter = $filters['q'] !== '' || $filters['categories'] !== [];

/** Name lookup so active chips can show labels without another query. */
$catNames = [];
foreach ($taxonomy as $g) {
    foreach ($g['categories'] as $c) {
        $catNames[$c['slug']] = $c['name'];
    }
}

layout_start($pageTitle, '', ['mainClass' => 'library']);
?>

<div class="library-head">
    <p class="page-eyebrow">Design Team</p>
    <h1 class="page-title">Asset Library</h1>
    <p class="page-subtitle">Browse brand and campaign assets, then grab the template or request something new.</p>
</div>

<div class="library-shell">

    <!-- ── Filters ──────────────────────────────────────────────────────── -->
    <aside class="filters" id="filters">
        <div class="filters-head">
            <h2 class="filters-title">Filters</h2>
            <button type="button" class="filters-clear" id="clear-filters" <?= $filters['categories'] ? '' : 'hidden' ?>>Clear all</button>
        </div>

        <?php if (!$taxonomy): ?>
            <p class="filters-empty">
                No filter groups yet.
                <?php if (effective_admin()): ?>
                    <a href="<?= e(url('/admin/filters')) ?>">Create the first one</a> to let people narrow the library.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <?php foreach ($taxonomy as $group): ?>
                <?php if (!$group['categories']) { continue; } ?>
                <div class="filter-group">
                    <h3 class="filter-group-name"><?= e($group['name']) ?></h3>
                    <?php foreach ($group['categories'] as $cat): ?>
                        <label class="filter-option">
                            <input type="checkbox" class="filter-cb" value="<?= e($cat['slug']) ?>"
                                   <?= isset($selected[$cat['slug']]) ? 'checked' : '' ?>>
                            <span class="filter-option-name"><?= e($cat['name']) ?></span>
                            <?php if ($cat['asset_count'] !== null): ?>
                                <span class="filter-count"><?= (int) $cat['asset_count'] ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </aside>

    <!-- ── Results ──────────────────────────────────────────────────────── -->
    <section class="library-main">
        <div class="search-row">
            <div class="search-wrap">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" id="asset-search" value="<?= e($filters['q']) ?>"
                       placeholder="Search assets by name, description or category…"
                       autocomplete="off" spellcheck="false" aria-label="Search assets">
                <button type="button" id="search-clear" title="Clear search" aria-label="Clear search"
                        <?= $filters['q'] === '' ? 'hidden' : '' ?>>&times;</button>
            </div>
        </div>

        <div class="result-bar">
            <span id="result-count"><?= count($assets) ?> <?= count($assets) === 1 ? 'asset' : 'assets' ?></span>
            <div class="active-chips" id="active-chips">
                <?php foreach ($filters['categories'] as $slug): ?>
                    <?php if (!isset($catNames[$slug])) { continue; } ?>
                    <button type="button" class="chip" data-slug="<?= e($slug) ?>">
                        <?= e($catNames[$slug]) ?><span class="x">&times;</span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="asset-grid" id="asset-grid">
            <?php if (!$assets): ?>
                <?php require APP_ROOT . '/views/partials/empty_grid.php'; ?>
            <?php else: ?>
                <?php foreach ($assets as $a): ?>
                    <button type="button" class="asset-card" data-slug="<?= e($a['slug']) ?>">
                        <?php if ($a['status'] === 'draft'): ?>
                            <span class="asset-card-badge">Draft</span>
                        <?php endif; ?>
                        <div class="asset-thumb <?= $a['thumb_url'] ? '' : 'is-empty' ?>">
                            <?php if ($a['thumb_url']): ?>
                                <img src="<?= e($a['thumb_url']) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="asset-card-body">
                            <span class="asset-card-title"><?= e($a['title']) ?></span>
                            <?php if ($a['summary'] !== ''): ?>
                                <span class="asset-card-summary"><?= e(str_excerpt($a['summary'], 96)) ?></span>
                            <?php endif; ?>
                        </div>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ── Asset modal ──────────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="asset-modal" hidden role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal" id="modal-panel"></div>
</div>

<script>
window.AL_STATE = <?= json_encode([
    'q'          => $filters['q'],
    'categories' => $filters['categories'],
    'openAsset'  => $openAsset ? asset_modal_payload($openAsset) : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= e(url('/assets/js/library.js')) ?>?v=<?= e(APP_VERSION) ?>"></script>

<?php layout_end(); ?>
