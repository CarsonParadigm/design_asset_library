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
            <table class="table" style="margin-bottom:18px;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th style="width:90px;">Order</th>
                        <th style="width:90px;">Assets</th>
                        <th style="width:170px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($group['categories'] as $cat): ?>
                    <tr>
                        <td colspan="4" style="padding:8px 12px;">
                            <form method="post" action="<?= e(url('/admin/filters/category/save')) ?>"
                                  style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                                <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                <input type="text" name="name" value="<?= e($cat['name']) ?>"
                                       required maxlength="120" style="flex:1;min-width:200px;">
                                <input type="number" name="sort_order" value="<?= (int) $cat['sort_order'] ?>"
                                       style="width:90px;" aria-label="Sort order">
                                <span style="width:70px;font-size:12.5px;color:var(--muted);text-align:center;">
                                    <?= (int) ($cat['asset_count'] ?? 0) ?>
                                </span>
                                <button class="btn btn-secondary btn-sm" type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="4" style="padding:0 12px 10px;border-bottom:1px solid #f0ebe0;">
                            <form method="post"
                                  action="<?= e(url('/admin/filters/category/' . $cat['id'] . '/delete')) ?>"
                                  onsubmit="return confirm('Delete the category “<?= e(addslashes($cat['name'])) ?>”? It will be removed from every asset that uses it.');">
                                <?= csrf_field() ?>
                                <button class="btn btn-danger btn-sm" type="submit">Delete category</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/admin/filters/category/save')) ?>"
              style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
            <div class="field" style="flex:1;min-width:220px;margin-bottom:0;">
                <label>Add a category</label>
                <input type="text" name="name" required maxlength="120" placeholder="e.g. Flyer">
            </div>
            <div class="field" style="width:110px;margin-bottom:0;">
                <label>Order</label>
                <input type="number" name="sort_order" value="<?= count($group['categories']) ?>">
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

<?php layout_end(); ?>
