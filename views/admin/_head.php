<?php
/**
 * Shared admin chrome: page heading, tab bar and any queued flash messages.
 *
 * Expects: $pageTitle, $activeTab ('assets'|'filters'), and optionally $headAction (HTML).
 */
declare(strict_types=1);
?>
<div class="admin-head">
    <div>
        <p class="page-eyebrow">Asset Library</p>
        <h1 class="page-title"><?= e($heading ?? $pageTitle) ?></h1>
        <?php if (!empty($subtitle)): ?>
            <p class="page-subtitle"><?= e($subtitle) ?></p>
        <?php endif; ?>
    </div>
    <?php if (!empty($headAction)): ?>
        <div><?= $headAction ?></div>
    <?php endif; ?>
</div>

<nav class="admin-tabs">
    <a class="admin-tab <?= ($activeTab ?? '') === 'assets' ? 'active' : '' ?>"
       href="<?= e(url('/admin/assets')) ?>">Assets</a>
    <a class="admin-tab <?= ($activeTab ?? '') === 'filters' ? 'active' : '' ?>"
       href="<?= e(url('/admin/filters')) ?>">Filter groups</a>
</nav>

<?php foreach (flash_take() as $f): ?>
    <div class="flash flash-<?= $f['type'] === 'ok' ? 'ok' : 'error' ?>">
        <?= e($f['message']) ?>
        <?php if (!empty($f['details'])): ?>
            <ul><?php foreach ($f['details'] as $d): ?><li><?= e($d) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
