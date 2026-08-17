<?php
/**
 * Admin: manage filter groups and the categories inside them.
 *
 * One page rather than a group list plus per-group screens — the whole taxonomy is small
 * enough to see at once, and adding a category next to its siblings is the common task.
 *
 * Expects: $taxonomy, $pageTitle.
 */
declare(strict_types=1);

require_once APP_ROOT . '/views/layout.php';

$activeTab = 'filters';
$heading   = 'Filter groups';
$subtitle  = 'Groups become the filter headings on the library; their categories are the checkboxes underneath.';

layout_start($pageTitle, '', ['mainClass' => 'admin']);
require APP_ROOT . '/views/admin/_head.php';
?>

<div class="panel">
    <h2 class="panel-title">Add a filter group</h2>
    <p class="panel-hint">
        A group is one way of slicing the library — “Asset Type”, “Service Line”, “Channel”.
        Give it categories below once it exists.
    </p>
    <form method="post" action="<?= e(url('/admin/filters/group/save')) ?>">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="field">
                <label for="new-group-name">Group name</label>
                <input type="text" id="new-group-name" name="name" required maxlength="120"
                       placeholder="e.g. Asset Type">
            </div>
            <div class="field">
                <label for="new-group-sort">Sort order</label>
                <input type="number" id="new-group-sort" name="sort_order" value="<?= count($taxonomy) ?>">
                <p class="field-hint">Lower numbers appear first.</p>
            </div>
        </div>
        <button class="btn btn-primary" type="submit">Create group</button>
    </form>
</div>

<?php if (!$taxonomy): ?>
    <div class="panel">
        <div class="empty-state" style="border:none;box-shadow:none;padding:30px 20px;">
            <h3>No filter groups yet</h3>
            <p>Create one above. Until a group exists, the library shows no filters — search
               still works.</p>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($taxonomy as $group): ?>
    <div class="panel">
        <form method="post" action="<?= e(url('/admin/filters/group/save')) ?>"
              style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:6px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $group['id'] ?>">
            <div class="field" style="flex:1;min-width:220px;margin-bottom:0;">
                <label>Group name</label>
                <input type="text" name="name" value="<?= e($group['name']) ?>" required maxlength="120">
            </div>
            <div class="field" style="width:110px;margin-bottom:0;">
                <label>Order</label>
                <input type="number" name="sort_order" value="<?= (int) $group['sort_order'] ?>">
            </div>
            <button class="btn btn-secondary" type="submit">Save</button>
        </form>
        <p class="panel-hint" style="margin-bottom:16px;">
            URL slug: <code><?= e($group['slug']) ?></code>
        </p>

        <div class="field-label">Categories</div>
        <?php if (!$group['categories']): ?>
            <p class="panel-hint">No categories in this group yet.</p>
        <?php else: ?>
            <p class="panel-hint" style="margin-bottom:10px;">
                Drag a row by its handle to reorder — the new order saves on drop.
            </p>

            <div class="cat-list" data-group-id="<?= (int) $group['id'] ?>">
                <div class="cat-row cat-row-head" aria-hidden="true">
                    <span></span>
                    <span>Name</span>
                    <span class="cat-col-count">Assets</span>
                    <span></span>
                    <span></span>
                </div>

                <?php foreach ($group['categories'] as $cat): ?>
                    <?php $cid = (int) $cat['id']; ?>
                    <!-- The row IS the save form. Delete lives in its own form further down and is
                         wired to this row's button with the `form` attribute, because forms cannot
                         nest. -->
                    <form class="cat-row" method="post" action="<?= e(url('/admin/filters/category/save')) ?>"
                          data-cat-id="<?= $cid ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $cid ?>">
                        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                        <input type="hidden" name="sort_order" value="<?= (int) $cat['sort_order'] ?>" class="cat-sort">

                        <span class="cat-drag" draggable="true" role="button" tabindex="0"
                              aria-label="Reorder <?= e($cat['name']) ?>"
                              title="Drag to reorder (or focus and use the arrow keys)">
                            <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
                                <circle cx="6" cy="3" r="1.35"/><circle cx="10" cy="3" r="1.35"/>
                                <circle cx="6" cy="8" r="1.35"/><circle cx="10" cy="8" r="1.35"/>
                                <circle cx="6" cy="13" r="1.35"/><circle cx="10" cy="13" r="1.35"/>
                            </svg>
                        </span>

                        <input type="text" name="name" class="cat-name" value="<?= e($cat['name']) ?>"
                               required maxlength="120" aria-label="Category name">

                        <span class="cat-col-count"><?= (int) ($cat['asset_count'] ?? 0) ?></span>

                        <button type="submit" class="icon-btn icon-btn-save" disabled
                                title="Save changes" aria-label="Save changes to <?= e($cat['name']) ?>">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </button>

                        <button type="submit" class="icon-btn icon-btn-delete"
                                form="del-cat-<?= $cid ?>"
                                title="Delete category" aria-label="Delete <?= e($cat['name']) ?>">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                <path d="M10 11v6M14 11v6"/>
                                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                            </svg>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>

            <?php foreach ($group['categories'] as $cat): ?>
                <form id="del-cat-<?= (int) $cat['id'] ?>" method="post" hidden
                      action="<?= e(url('/admin/filters/category/' . $cat['id'] . '/delete')) ?>"
                      onsubmit="return confirm('Delete the category “<?= e(addslashes($cat['name'])) ?>”? It will be removed from every asset that uses it.');">
                    <?= csrf_field() ?>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/admin/filters/category/save')) ?>"
              style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
            <!-- New categories land at the end; position is changed by dragging, not typing. -->
            <input type="hidden" name="sort_order" value="<?= count($group['categories']) ?>">
            <div class="field" style="flex:1;min-width:220px;margin-bottom:0;">
                <label>Add a category</label>
                <input type="text" name="name" required maxlength="120" placeholder="e.g. Flyer">
            </div>
            <button class="btn btn-primary" type="submit">Add</button>
        </form>

        <div class="form-actions">
            <div class="spacer"></div>
            <form method="post" action="<?= e(url('/admin/filters/group/' . $group['id'] . '/delete')) ?>"
                  onsubmit="return confirm('Delete the group “<?= e(addslashes($group['name'])) ?>” and all <?= count($group['categories']) ?> of its categories? This cannot be undone.');">
                <?= csrf_field() ?>
                <button class="btn btn-danger btn-sm" type="submit">Delete group</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<script src="<?= e(url('/assets/js/filters.js')) ?>?v=<?= e(APP_VERSION) ?>"></script>

<?php layout_end(); ?>
