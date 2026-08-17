<?php
/**
 * Backend area — taxonomy and asset management.
 *
 * Every route here is behind require_admin() (see index.php) and every mutating route starts
 * with csrf_check(). Results are reported back through a one-shot flash in the session so a
 * successful POST can redirect rather than leaving a re-submittable form.
 */
declare(strict_types=1);

/** Queue a message for the next page render. */
function flash(string $type, string $message, array $details = []): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message, 'details' => $details];
}

/** Read and clear queued messages. */
function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ── Assets ───────────────────────────────────────────────────────────────────────────── */

function admin_assets_index(): never
{
    $assets    = assets_search(['status' => 'any']);
    $pageTitle = 'Manage assets';
    require APP_ROOT . '/views/admin/assets_index.php';
    exit;
}

function admin_asset_form(?int $id): never
{
    $asset = null;
    if ($id !== null) {
        $asset = asset_find($id, 'any');
        if (!$asset) {
            http_response_code(404);
            $pageTitle = 'Not found';
            require APP_ROOT . '/views/404.php';
            exit;
        }
    }

    $taxonomy  = taxonomy_all();
    $selected  = array_flip(array_column($asset['categories'] ?? [], 'id'));
    $pageTitle = $asset ? 'Edit ' . $asset['title'] : 'New asset';
    require APP_ROOT . '/views/admin/asset_form.php';
    exit;
}

/**
 * Create or update an asset from the single multipart form.
 *
 * Images are handled after the row exists (a new asset has no id to attach them to yet), and
 * per-file failures are collected rather than thrown — one oversized image should not discard
 * the title, body and CTAs the user just typed.
 */
function admin_asset_save(?int $id): never
{
    csrf_check();

    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        flash('error', 'An asset needs a title.');
        redirect($id ? url("/admin/assets/$id/edit") : url('/admin/assets/new'));
    }

    $assetId = asset_save($id, [
        'title'      => $title,
        'slug'       => trim((string) ($_POST['slug'] ?? '')) ?: $title,
        'summary'    => (string) ($_POST['summary'] ?? ''),
        'body_html'  => (string) ($_POST['body_html'] ?? ''),
        'status'     => (string) ($_POST['status'] ?? 'published'),
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
    ]);

    asset_set_categories($assetId, (array) ($_POST['categories'] ?? []));

    // Repeater arrays arrive as parallel lists; zip them back into rows.
    $labels = (array) ($_POST['cta_label'] ?? []);
    $urls   = (array) ($_POST['cta_url'] ?? []);
    $styles = (array) ($_POST['cta_style'] ?? []);
    $ctas   = [];
    foreach ($labels as $i => $label) {
        $ctas[] = [
            'label' => (string) $label,
            'url'   => (string) ($urls[$i] ?? ''),
            'style' => (string) ($styles[$i] ?? 'primary'),
        ];
    }
    asset_set_ctas($assetId, $ctas);

    $errors = [];

    // Deletions first, so replacing images doesn't trip the gallery limit.
    foreach ((array) ($_POST['delete_image'] ?? []) as $imageId) {
        asset_image_delete($assetId, (int) $imageId);
    }

    // Featured image (replaces the current one).
    $featured = normalize_files($_FILES['featured_image'] ?? []);
    if ($featured) {
        try {
            $stored = image_ingest($featured[0]);
            $newId  = asset_image_add($assetId, $stored, 'featured', -1);
            asset_set_featured($assetId, $newId);
        } catch (ImageUploadError $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('asset-library: featured upload failed: ' . $e->getMessage());
            $errors[] = 'The featured image could not be saved. Please try again.';
        }
    }

    // Gallery images (appended, capped).
    $gallery = normalize_files($_FILES['gallery_images'] ?? []);
    if ($gallery) {
        $room = MAX_GALLERY_IMAGES - asset_gallery_count($assetId);
        if ($room <= 0) {
            $errors[] = sprintf(
                'This asset already has the maximum of %d gallery images. Remove one before adding more.',
                MAX_GALLERY_IMAGES
            );
        } else {
            if (count($gallery) > $room) {
                $errors[] = sprintf(
                    'Only %d more gallery image%s could be added (limit is %d); the rest were skipped.',
                    $room, $room === 1 ? '' : 's', MAX_GALLERY_IMAGES
                );
                $gallery = array_slice($gallery, 0, $room);
            }
            $next = asset_gallery_count($assetId);
            foreach ($gallery as $file) {
                try {
                    $stored = image_ingest($file);
                    asset_image_add($assetId, $stored, 'gallery', $next++);
                } catch (ImageUploadError $e) {
                    $errors[] = $e->getMessage();
                } catch (Throwable $e) {
                    error_log('asset-library: gallery upload failed: ' . $e->getMessage());
                    $errors[] = sprintf('“%s” could not be saved.', $file['name'] ?? 'An image');
                }
            }
        }
    }

    // Explicit featured choice among existing images, if the user picked one.
    $setFeatured = (int) ($_POST['set_featured'] ?? 0);
    if ($setFeatured > 0) {
        asset_set_featured($assetId, $setFeatured);
    }
    asset_normalize_featured($assetId);

    if ($errors) {
        flash('error', 'The asset was saved, but some images had problems:', $errors);
    } else {
        flash('ok', $id ? 'Asset updated.' : 'Asset created.');
    }
    redirect(url("/admin/assets/$assetId/edit"));
}

function admin_asset_delete(int $id): never
{
    csrf_check();
    $asset = asset_find($id, 'any');
    asset_delete($id);
    flash('ok', $asset ? sprintf('“%s” was deleted.', $asset['title']) : 'Asset deleted.');
    redirect(url('/admin/assets'));
}

/* ── Taxonomy ─────────────────────────────────────────────────────────────────────────── */

function admin_filters_index(): never
{
    $taxonomy  = taxonomy_all(true);
    $pageTitle = 'Filter groups';
    require APP_ROOT . '/views/admin/filters.php';
    exit;
}

function admin_group_save(): never
{
    csrf_check();
    $name = trim((string) ($_POST['name'] ?? ''));
    $id   = (int) ($_POST['id'] ?? 0) ?: null;

    if ($name === '') {
        flash('error', 'A filter group needs a name.');
        redirect(url('/admin/filters'));
    }
    group_save($id, $name, (int) ($_POST['sort_order'] ?? 0));
    flash('ok', $id ? 'Filter group updated.' : sprintf('Filter group “%s” created.', $name));
    redirect(url('/admin/filters'));
}

function admin_group_delete(int $id): never
{
    csrf_check();
    $group = group_find($id);
    group_delete($id);
    flash('ok', $group
        ? sprintf('“%s” and its categories were deleted.', $group['name'])
        : 'Filter group deleted.');
    redirect(url('/admin/filters'));
}

function admin_category_save(): never
{
    csrf_check();
    $name    = trim((string) ($_POST['name'] ?? ''));
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $id      = (int) ($_POST['id'] ?? 0) ?: null;

    if ($name === '' || $groupId <= 0) {
        flash('error', 'A category needs a name and a filter group.');
        redirect(url('/admin/filters'));
    }
    category_save($id, $groupId, $name, (int) ($_POST['sort_order'] ?? 0));
    flash('ok', $id ? 'Category updated.' : sprintf('Category “%s” added.', $name));
    redirect(url('/admin/filters'));
}

function admin_category_delete(int $id): never
{
    csrf_check();
    $cat = category_find($id);
    category_delete($id);
    flash('ok', $cat
        ? sprintf('“%s” was removed from every asset that used it.', $cat['name'])
        : 'Category deleted.');
    redirect(url('/admin/filters'));
}
