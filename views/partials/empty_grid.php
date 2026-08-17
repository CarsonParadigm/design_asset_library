<?php
/**
 * Shown when the grid has nothing in it — distinguishes "nothing matched your search" from
 * "the library is empty", because the useful next step is different for each.
 *
 * Expects: $hasFilter (bool).
 */
declare(strict_types=1);
?>
<div class="empty-state">
    <div class="empty-emblem">
        <svg viewBox="0 0 24 24">
            <?php if ($hasFilter): ?>
                <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            <?php else: ?>
                <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
            <?php endif; ?>
        </svg>
    </div>

    <?php if ($hasFilter): ?>
        <h3>No assets match those filters</h3>
        <p>Try removing a filter or searching for a broader term.</p>
        <a class="btn btn-secondary" href="<?= e(url('/')) ?>">Clear filters</a>
    <?php else: ?>
        <h3>The library is empty</h3>
        <?php if (is_admin()): ?>
            <p>Add your first asset — give it a title, a featured image, and the buttons people
               should click, then assign it to categories so it can be found.</p>
            <a class="btn btn-primary" href="<?= e(url('/admin/assets/new')) ?>">Add the first asset</a>
        <?php else: ?>
            <p>No assets have been published yet. Check back shortly.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
