<?php
/**
 * Admin: create/edit one asset.
 *
 * A single multipart form covers the lot — text, categories, CTA repeater, featured image and
 * gallery. One submit means one round of validation and no half-saved state to reconcile.
 *
 * Expects: $asset (array|null), $taxonomy, $selected (category id => any), $pageTitle.
 */
declare(strict_types=1);

require_once APP_ROOT . '/views/layout.php';

$isNew  = $asset === null;
$action = $isNew ? url('/admin/assets/create') : url('/admin/assets/' . $asset['id'] . '/save');

$images   = $asset['images'] ?? [];
$featured = null;
$gallery  = [];
foreach ($images as $img) {
    if ($img['role'] === 'featured' && $featured === null) {
        $featured = $img;
    } else {
        $gallery[] = $img;
    }
}

$activeTab  = 'assets';
$heading    = $isNew ? 'New asset' : 'Edit asset';
$subtitle   = $isNew ? null : $asset['title'];
$headAction = '<a class="btn btn-secondary" href="' . e(url('/admin/assets')) . '">Back to assets</a>';

layout_start($pageTitle, '', ['mainClass' => 'admin', 'quill' => true]);
require APP_ROOT . '/views/admin/_head.php';
?>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" id="asset-form">
    <?= csrf_field() ?>

    <!-- ── Basics ───────────────────────────────────────────────────────── -->
    <div class="panel">
        <h2 class="panel-title">Basics</h2>
        <p class="panel-hint">The title and summary are what people see on the grid.</p>

        <div class="field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required maxlength="200"
                   value="<?= e($asset['title'] ?? '') ?>" placeholder="e.g. Practice Opening Announcement">
        </div>

        <div class="field">
            <label for="summary">Short summary</label>
            <textarea id="summary" name="summary" maxlength="400" rows="2"
                      placeholder="One or two lines shown on the asset card."><?= e($asset['summary'] ?? '') ?></textarea>
        </div>

        <div class="form-row">
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="published" <?= ($asset['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>Published — visible to everyone</option>
                    <option value="draft" <?= ($asset['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft — hidden from the library</option>
                </select>
            </div>
            <div class="field">
                <label for="sort_order">Sort order</label>
                <input type="number" id="sort_order" name="sort_order" value="<?= (int) ($asset['sort_order'] ?? 0) ?>">
                <p class="field-hint">Lower numbers appear first in the grid.</p>
            </div>
            <?php if (!$isNew): ?>
            <div class="field">
                <label for="slug">URL slug</label>
                <input type="text" id="slug" name="slug" maxlength="200" value="<?= e($asset['slug']) ?>">
                <p class="field-hint">Changing this breaks existing shared links.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Images ───────────────────────────────────────────────────────── -->
    <div class="panel">
        <h2 class="panel-title">Images</h2>
        <p class="panel-hint">
            The featured image fronts the asset on the grid; gallery images give people other
            views. Up to <?= MAX_GALLERY_IMAGES ?> gallery images, <?= e(format_bytes(MAX_UPLOAD_BYTES)) ?> each.
            JPEG, PNG, WebP or GIF — everything is resized automatically.
        </p>

        <div class="form-row">
            <div class="field">
                <label for="featured_image">Featured image<?= $featured ? ' (replace)' : '' ?></label>
                <input type="file" id="featured_image" name="featured_image" accept="image/*">
            </div>
            <div class="field">
                <label for="gallery_images">Add gallery images</label>
                <input type="file" id="gallery_images" name="gallery_images[]" accept="image/*" multiple>
                <p class="field-hint" id="gallery-room">
                    <?= MAX_GALLERY_IMAGES - count($gallery) ?> slot<?= (MAX_GALLERY_IMAGES - count($gallery)) === 1 ? '' : 's' ?> remaining.
                </p>
            </div>
        </div>

        <?php if ($images): ?>
            <div class="field-label" style="margin-top:8px;">Current images</div>
            <p class="field-hint" style="margin:0 0 14px;">
                Pick which image is featured, or tick “Remove” and save.
            </p>
            <div class="image-manager">
                <?php foreach ($images as $img): ?>
                    <?php $isFeat = $img['role'] === 'featured'; ?>
                    <div class="image-tile <?= $isFeat ? 'is-featured' : '' ?>">
                        <div class="image-tile-img">
                            <img src="<?= e($img['thumb_url']) ?>" alt="<?= e($img['orig_name']) ?>" loading="lazy">
                        </div>
                        <div class="image-tile-foot">
                            <label>
                                <input type="radio" name="set_featured" value="<?= (int) $img['id'] ?>"
                                       <?= $isFeat ? 'checked' : '' ?>>
                                <span class="<?= $isFeat ? 'featured-mark' : '' ?>">Featured</span>
                            </label>
                            <label>
                                <input type="checkbox" name="delete_image[]" value="<?= (int) $img['id'] ?>">
                                <span>Remove</span>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif (!$isNew): ?>
            <p class="field-hint">No images yet.</p>
        <?php endif; ?>
    </div>

    <!-- ── CTAs ─────────────────────────────────────────────────────────── -->
    <div class="panel">
        <h2 class="panel-title">Buttons</h2>
        <p class="panel-hint">
            Shown in the asset modal — typically “View Template on Canva” and “Submit a Creative
            Request”. Every button opens in a new tab. Add as many as you need.
        </p>

        <div id="cta-rows">
            <?php
            $ctas = $asset['ctas'] ?? [];
            if (!$ctas) {
                // Seed a new asset with the two buttons this library almost always uses, so the
                // common case is fill-in-the-URL rather than build-from-scratch.
                $ctas = [
                    ['label' => 'View Template on Canva',   'url' => '', 'style' => 'primary'],
                    ['label' => 'Submit a Creative Request', 'url' => '', 'style' => 'secondary'],
                ];
            }
            foreach ($ctas as $cta): ?>
                <div class="repeater-row">
                    <input type="text" name="cta_label[]" placeholder="Button text"
                           maxlength="120" value="<?= e($cta['label']) ?>">
                    <input type="url" name="cta_url[]" placeholder="https://…"
                           maxlength="1000" value="<?= e($cta['url']) ?>">
                    <select name="cta_style[]" style="width:130px;">
                        <option value="primary" <?= $cta['style'] === 'primary' ? 'selected' : '' ?>>Primary</option>
                        <option value="secondary" <?= $cta['style'] === 'secondary' ? 'selected' : '' ?>>Secondary</option>
                    </select>
                    <button type="button" class="icon-btn cta-remove" aria-label="Remove button">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="btn btn-secondary btn-sm" id="cta-add">+ Add button</button>
    </div>

    <!-- ── Details ──────────────────────────────────────────────────────── -->
    <div class="panel">
        <h2 class="panel-title">Details</h2>
        <p class="panel-hint">
            Shown beneath the buttons in the asset modal. Use the video button to embed a
            YouTube, Vimeo or Loom link.
        </p>
        <div class="editor-shell">
            <div id="body-editor"><?= $asset['body_html'] ?? '' ?></div>
        </div>
        <input type="hidden" name="body_html" id="body_html">
    </div>

    <!-- ── Categories ───────────────────────────────────────────────────── -->
    <div class="panel">
        <h2 class="panel-title">Categories</h2>
        <p class="panel-hint">
            Assigning categories is what makes an asset findable through the library's filters.
        </p>

        <?php if (!$taxonomy): ?>
            <p class="field-hint">
                No filter groups exist yet.
                <a href="<?= e(url('/admin/filters')) ?>">Create one</a> to categorise assets.
            </p>
        <?php else: ?>
            <div class="cat-groups">
                <?php foreach ($taxonomy as $group): ?>
                    <?php if (!$group['categories']) { continue; } ?>
                    <div>
                        <div class="cat-group-name"><?= e($group['name']) ?></div>
                        <?php foreach ($group['categories'] as $cat): ?>
                            <label class="cat-check">
                                <input type="checkbox" name="categories[]" value="<?= (int) $cat['id'] ?>"
                                       <?= isset($selected[$cat['id']]) ? 'checked' : '' ?>>
                                <span><?= e($cat['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Create asset' : 'Save changes' ?></button>
        <a class="btn btn-secondary" href="<?= e(url('/admin/assets')) ?>">Cancel</a>
        <?php if (!$isNew): ?>
            <a class="btn btn-secondary" href="<?= e(url('/asset/' . $asset['slug'])) ?>" target="_blank" rel="noopener">View in library</a>
        <?php endif; ?>
        <div class="spacer"></div>
    </div>
</form>

<?php if (!$isNew): ?>
    <!-- Kept outside the main form: nested forms are invalid HTML and the inner one is ignored. -->
    <div class="panel">
        <h2 class="panel-title">Delete this asset</h2>
        <p class="panel-hint">Removes the asset and its images permanently. This cannot be undone.</p>
        <form method="post" action="<?= e(url('/admin/assets/' . $asset['id'] . '/delete')) ?>"
              onsubmit="return confirm('Delete “<?= e(addslashes($asset['title'])) ?>” and all its images? This cannot be undone.');">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit">Delete asset</button>
        </form>
    </div>
<?php endif; ?>

<script src="<?= e(url('/vendor/quill/quill.js')) ?>"></script>
<script src="<?= e(url('/assets/js/admin.js')) ?>?v=<?= e(APP_VERSION) ?>"></script>
<script>
    ASSET_ADMIN.init({
        maxGallery: <?= MAX_GALLERY_IMAGES ?>,
        usedGallery: <?= count($gallery) ?>,
        maxBytes: <?= MAX_UPLOAD_BYTES ?>,
        maxBytesLabel: <?= json_encode(format_bytes(MAX_UPLOAD_BYTES)) ?>
    });
</script>

<?php layout_end(); ?>
