<?php
/**
 * Admin: list of every asset, published and draft.
 *
 * Expects: $assets, $pageTitle.
 */
declare(strict_types=1);

require_once APP_ROOT . '/views/layout.php';

$activeTab  = 'assets';
$heading    = 'Assets';
$subtitle   = 'Everything in the library. Drafts are hidden from the public grid.';
$headAction = '<a class="btn btn-primary" href="' . e(url('/admin/assets/new')) . '">+ New asset</a>';

layout_start($pageTitle, '', ['mainClass' => 'admin']);
require APP_ROOT . '/views/admin/_head.php';
?>

<div class="panel">
    <?php if (!$assets): ?>
        <div class="empty-state" style="border:none;box-shadow:none;padding:36px 20px;">
            <div class="empty-emblem">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            </div>
            <h3>No assets yet</h3>
            <p>Create your first asset — a title, a featured image, the buttons people should
               click, and the categories it belongs to.</p>
            <a class="btn btn-primary" href="<?= e(url('/admin/assets/new')) ?>">+ New asset</a>
        </div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:70px;">Image</th>
                    <th>Title</th>
                    <th style="width:110px;">Status</th>
                    <th style="width:150px;">Updated</th>
                    <th style="width:180px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($assets as $a): ?>
                <tr>
                    <td>
                        <?php if ($a['thumb_url']): ?>
                            <img class="table-thumb" src="<?= e($a['thumb_url']) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="table-thumb"></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="table-title"><?= e($a['title']) ?></div>
                        <?php if ($a['summary'] !== ''): ?>
                            <div style="font-size:12.5px;color:var(--muted);margin-top:3px;">
                                <?= e(str_excerpt($a['summary'], 90)) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $a['status'] === 'draft' ? 'draft' : 'published' ?>">
                            <?= e($a['status']) ?>
                        </span>
                    </td>
                    <td style="font-size:13px;color:var(--muted);">
                        <?= e(date('M j, Y', strtotime((string) $a['updated_at']))) ?>
                    </td>
                    <td>
                        <div class="table-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= e(url('/asset/' . $a['slug'])) ?>"
                               target="_blank" rel="noopener">View</a>
                            <a class="btn btn-navy btn-sm" href="<?= e(url('/admin/assets/' . $a['id'] . '/edit')) ?>">Edit</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php layout_end(); ?>
