<?php
/**
 * Data access. Plain PDO, no ORM — the query shapes here are the whole data layer.
 */
declare(strict_types=1);

/* ── Taxonomy ─────────────────────────────────────────────────────────────────────────── */

/**
 * Every filter group with its categories nested, in display order.
 *
 * @return list<array{id:int,name:string,slug:string,categories:list<array>}>
 */
function taxonomy_all(bool $withCounts = false): array
{
    $groups = db()->query(
        'SELECT id, name, slug, sort_order FROM filter_groups ORDER BY sort_order, name'
    )->fetchAll();
    if (!$groups) {
        return [];
    }

    // One extra query for all categories beats one per group.
    $counts = $withCounts
        ? ', (SELECT COUNT(*) FROM asset_categories ac
                JOIN assets a ON a.id = ac.asset_id AND a.status = "published"
               WHERE ac.category_id = c.id) AS asset_count'
        : '';
    $cats = db()->query(
        "SELECT c.id, c.group_id, c.name, c.slug, c.sort_order $counts
           FROM filter_categories c ORDER BY c.sort_order, c.name"
    )->fetchAll();

    $byGroup = [];
    foreach ($cats as $c) {
        $byGroup[(int) $c['group_id']][] = [
            'id'          => (int) $c['id'],
            'name'        => $c['name'],
            'slug'        => $c['slug'],
            'sort_order'  => (int) $c['sort_order'],
            'asset_count' => isset($c['asset_count']) ? (int) $c['asset_count'] : null,
        ];
    }

    $out = [];
    foreach ($groups as $g) {
        $out[] = [
            'id'         => (int) $g['id'],
            'name'       => $g['name'],
            'slug'       => $g['slug'],
            'sort_order' => (int) $g['sort_order'],
            'categories' => $byGroup[(int) $g['id']] ?? [],
        ];
    }
    return $out;
}

function group_find(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM filter_groups WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function group_save(?int $id, string $name, int $sortOrder): int
{
    $slug = unique_slug('filter_groups', slugify($name), $id);
    if ($id) {
        $st = db()->prepare('UPDATE filter_groups SET name = ?, slug = ?, sort_order = ? WHERE id = ?');
        $st->execute([$name, $slug, $sortOrder, $id]);
        return $id;
    }
    $st = db()->prepare('INSERT INTO filter_groups (name, slug, sort_order) VALUES (?, ?, ?)');
    $st->execute([$name, $slug, $sortOrder]);
    return (int) db()->lastInsertId();
}

/** Deleting a group cascades to its categories and to those categories' asset links. */
function group_delete(int $id): void
{
    $st = db()->prepare('DELETE FROM filter_groups WHERE id = ?');
    $st->execute([$id]);
}

function category_find(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM filter_categories WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function category_save(?int $id, int $groupId, string $name, int $sortOrder): int
{
    $slug = unique_slug('filter_categories', slugify($name), $id);
    if ($id) {
        $st = db()->prepare(
            'UPDATE filter_categories SET group_id = ?, name = ?, slug = ?, sort_order = ? WHERE id = ?'
        );
        $st->execute([$groupId, $name, $slug, $sortOrder, $id]);
        return $id;
    }
    $st = db()->prepare(
        'INSERT INTO filter_categories (group_id, name, slug, sort_order) VALUES (?, ?, ?, ?)'
    );
    $st->execute([$groupId, $name, $slug, $sortOrder]);
    return (int) db()->lastInsertId();
}

function category_delete(int $id): void
{
    $st = db()->prepare('DELETE FROM filter_categories WHERE id = ?');
    $st->execute([$id]);
}

/* ── Assets ───────────────────────────────────────────────────────────────────────────── */

/**
 * Browse/search assets.
 *
 * Facet semantics match how people expect filters to behave: selections *within* one group are
 * OR'd (Flyer or Poster), selections *across* groups are AND'd (a Flyer AND for Ortho). That
 * is why the category slugs are resolved to their groups first rather than being thrown into
 * one big IN () clause.
 *
 * @param array{q?:string,categories?:list<string>,status?:string,limit?:int} $opts
 * @return list<array>
 */
function assets_search(array $opts = []): array
{
    $q      = trim((string) ($opts['q'] ?? ''));
    $slugs  = array_values(array_filter($opts['categories'] ?? []));
    $status = $opts['status'] ?? 'published';

    $where  = [];
    $params = [];

    if ($status !== 'any') {
        $where[]  = 'a.status = ?';
        $params[] = $status;
    }

    if ($slugs) {
        // Group the requested slugs by their parent group.
        $in = implode(',', array_fill(0, count($slugs), '?'));
        $st = db()->prepare("SELECT id, group_id FROM filter_categories WHERE slug IN ($in)");
        $st->execute($slugs);

        $byGroup = [];
        foreach ($st->fetchAll() as $row) {
            $byGroup[(int) $row['group_id']][] = (int) $row['id'];
        }
        if (!$byGroup) {
            return []; // Every requested slug was unknown — no asset can match.
        }
        foreach ($byGroup as $ids) {
            $inIds    = implode(',', array_fill(0, count($ids), '?'));
            $where[]  = "EXISTS (SELECT 1 FROM asset_categories ac
                                  WHERE ac.asset_id = a.id AND ac.category_id IN ($inIds))";
            $params   = array_merge($params, $ids);
        }
    }

    if ($q !== '') {
        // Each term must appear somewhere in the asset (title, summary, body, or the name of
        // one of its categories), so extra words narrow the result set rather than widening it.
        foreach (preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            $like     = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';
            $where[]  = '(a.title LIKE ? OR a.summary LIKE ? OR a.body_text LIKE ?
                          OR EXISTS (SELECT 1 FROM asset_categories ac2
                                       JOIN filter_categories c2 ON c2.id = ac2.category_id
                                      WHERE ac2.asset_id = a.id AND c2.name LIKE ?))';
            array_push($params, $like, $like, $like, $like);
        }
    }

    $sql = 'SELECT a.id, a.title, a.slug, a.summary, a.status, a.sort_order, a.updated_at,
                   fi.path AS featured_path, fi.width AS featured_w, fi.height AS featured_h
              FROM assets a
              LEFT JOIN asset_images fi
                     ON fi.id = (SELECT i2.id FROM asset_images i2
                                  WHERE i2.asset_id = a.id
                               ORDER BY i2.role = "featured" DESC, i2.sort_order, i2.id
                                  LIMIT 1)';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY a.sort_order, a.title';
    if (!empty($opts['limit'])) {
        $sql .= ' LIMIT ' . (int) $opts['limit'];
    }

    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['thumb_url']    = $r['featured_path'] ? image_url(thumb_path($r['featured_path'])) : null;
        $r['featured_url'] = image_url($r['featured_path']);
    }
    return $rows;
}

function asset_find(int|string $key, string $status = 'published'): ?array
{
    $col = is_int($key) ? 'id' : 'slug';
    $sql = "SELECT * FROM assets WHERE $col = ?";
    if ($status !== 'any') {
        $sql .= ' AND status = ' . db()->quote($status);
    }
    $st = db()->prepare($sql);
    $st->execute([$key]);
    $asset = $st->fetch();
    if (!$asset) {
        return null;
    }

    $asset['id'] = (int) $asset['id'];
    $id          = $asset['id'];

    $st = db()->prepare(
        'SELECT id, role, path, orig_name, width, height, sort_order
           FROM asset_images WHERE asset_id = ?
       ORDER BY role = "featured" DESC, sort_order, id'
    );
    $st->execute([$id]);
    $asset['images'] = array_map(static function (array $i): array {
        $i['id']        = (int) $i['id'];
        $i['url']       = image_url($i['path']);
        $i['thumb_url'] = image_url(thumb_path($i['path']));
        return $i;
    }, $st->fetchAll());

    $st = db()->prepare('SELECT id, label, url, style, sort_order FROM asset_ctas
                          WHERE asset_id = ? ORDER BY sort_order, id');
    $st->execute([$id]);
    $asset['ctas'] = $st->fetchAll();

    $st = db()->prepare(
        'SELECT c.id, c.name, c.slug, g.name AS group_name, g.slug AS group_slug
           FROM asset_categories ac
           JOIN filter_categories c ON c.id = ac.category_id
           JOIN filter_groups g     ON g.id = c.group_id
          WHERE ac.asset_id = ?
       ORDER BY g.sort_order, g.name, c.sort_order, c.name'
    );
    $st->execute([$id]);
    $asset['categories'] = $st->fetchAll();

    return $asset;
}

/**
 * Create or update an asset's own columns. Images, CTAs and categories are handled separately
 * by the admin controller so it can report per-file upload errors.
 */
function asset_save(?int $id, array $data): int
{
    $bodyHtml = sanitize_rich_html((string) ($data['body_html'] ?? ''));
    $bodyText = html_to_text($bodyHtml);
    $title    = trim((string) $data['title']);
    $summary  = mb_substr(trim((string) ($data['summary'] ?? '')), 0, 400);
    $status   = ($data['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
    $sort     = (int) ($data['sort_order'] ?? 0);
    $oid      = current_user_id();

    if ($id) {
        $slug = unique_slug('assets', slugify($data['slug'] ?? $title), $id);
        $st   = db()->prepare(
            'UPDATE assets SET title = ?, slug = ?, summary = ?, body_html = ?, body_text = ?,
                    status = ?, sort_order = ?, updated_by_oid = ? WHERE id = ?'
        );
        $st->execute([$title, $slug, $summary, $bodyHtml, $bodyText, $status, $sort, $oid, $id]);
        return $id;
    }

    $slug = unique_slug('assets', slugify($data['slug'] ?? $title));
    $st   = db()->prepare(
        'INSERT INTO assets (title, slug, summary, body_html, body_text, status, sort_order,
                             created_by_oid, updated_by_oid)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$title, $slug, $summary, $bodyHtml, $bodyText, $status, $sort, $oid, $oid]);
    return (int) db()->lastInsertId();
}

/** Replace an asset's category links wholesale. */
function asset_set_categories(int $assetId, array $categoryIds): void
{
    $st = db()->prepare('DELETE FROM asset_categories WHERE asset_id = ?');
    $st->execute([$assetId]);

    $ids = array_values(array_unique(array_map('intval', $categoryIds)));
    if (!$ids) {
        return;
    }
    $st = db()->prepare('INSERT IGNORE INTO asset_categories (asset_id, category_id) VALUES (?, ?)');
    foreach ($ids as $cid) {
        $st->execute([$assetId, $cid]);
    }
}

/**
 * Replace an asset's CTAs wholesale.
 *
 * @param list<array{label:string,url:string,style?:string}> $ctas
 */
function asset_set_ctas(int $assetId, array $ctas): void
{
    $st = db()->prepare('DELETE FROM asset_ctas WHERE asset_id = ?');
    $st->execute([$assetId]);

    $st = db()->prepare(
        'INSERT INTO asset_ctas (asset_id, label, url, style, sort_order) VALUES (?, ?, ?, ?, ?)'
    );
    $i = 0;
    foreach ($ctas as $cta) {
        $label = trim((string) ($cta['label'] ?? ''));
        $url   = trim((string) ($cta['url'] ?? ''));
        if ($label === '' || $url === '' || !is_safe_url($url)) {
            continue; // Blank repeater rows, and anything that isn't a real http(s) link.
        }
        $style = ($cta['style'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary';
        $st->execute([$assetId, mb_substr($label, 0, 120), mb_substr($url, 0, 1000), $style, $i++]);
    }
}

function asset_delete(int $id): void
{
    // Remove the files first: the rows cascade away, and orphaned rows are easier to notice
    // and clean up than orphaned files would be.
    $st = db()->prepare('SELECT path FROM asset_images WHERE asset_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $path) {
        image_forget((string) $path);
    }

    $st = db()->prepare('DELETE FROM assets WHERE id = ?');
    $st->execute([$id]);
}

function asset_image_add(int $assetId, array $stored, string $role, int $sortOrder): int
{
    $st = db()->prepare(
        'INSERT INTO asset_images (asset_id, role, path, orig_name, mime, width, height, sort_order)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $assetId, $role, $stored['path'], $stored['orig_name'],
        $stored['mime'], $stored['width'], $stored['height'], $sortOrder,
    ]);
    return (int) db()->lastInsertId();
}

function asset_image_delete(int $assetId, int $imageId): void
{
    $st = db()->prepare('SELECT path FROM asset_images WHERE id = ? AND asset_id = ?');
    $st->execute([$imageId, $assetId]);
    $path = $st->fetchColumn();
    if ($path === false) {
        return;
    }
    image_forget((string) $path);

    $st = db()->prepare('DELETE FROM asset_images WHERE id = ? AND asset_id = ?');
    $st->execute([$imageId, $assetId]);
}

/** Promote one image to featured and demote the rest. */
function asset_set_featured(int $assetId, int $imageId): void
{
    $st = db()->prepare('UPDATE asset_images SET role = "gallery" WHERE asset_id = ?');
    $st->execute([$assetId]);

    $st = db()->prepare('UPDATE asset_images SET role = "featured" WHERE id = ? AND asset_id = ?');
    $st->execute([$imageId, $assetId]);
}

/** Ensure exactly one featured image exists, picking the first if none is marked. */
function asset_normalize_featured(int $assetId): void
{
    $st = db()->prepare('SELECT COUNT(*) FROM asset_images WHERE asset_id = ? AND role = "featured"');
    $st->execute([$assetId]);
    if ((int) $st->fetchColumn() === 1) {
        return;
    }

    $st = db()->prepare('SELECT id FROM asset_images WHERE asset_id = ? ORDER BY sort_order, id LIMIT 1');
    $st->execute([$assetId]);
    $first = $st->fetchColumn();
    if ($first !== false) {
        asset_set_featured($assetId, (int) $first);
    }
}

function asset_gallery_count(int $assetId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM asset_images WHERE asset_id = ? AND role = "gallery"');
    $st->execute([$assetId]);
    return (int) $st->fetchColumn();
}

function asset_image_reorder(int $assetId, array $orderedIds): void
{
    $st = db()->prepare('UPDATE asset_images SET sort_order = ? WHERE id = ? AND asset_id = ?');
    foreach (array_values($orderedIds) as $i => $imageId) {
        $st->execute([$i, (int) $imageId, $assetId]);
    }
}
