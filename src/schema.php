<?php
/**
 * Schema creation and migration.
 *
 * Runs on every request but is cheap: a single row read from schema_version short-circuits
 * when the database is already current. Migrations are append-only and idempotent — add a new
 * numbered step, never edit an applied one.
 */
declare(strict_types=1);

const SCHEMA_VERSION = 1;

function schema_migrate(): void
{
    $pdo = db();

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_version (
            id      TINYINT      NOT NULL PRIMARY KEY,
            version INT UNSIGNED NOT NULL
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $current = (int) ($pdo->query('SELECT version FROM schema_version WHERE id = 1')->fetchColumn() ?: 0);
    if ($current >= SCHEMA_VERSION) {
        return;
    }

    if ($current < 1) {
        schema_step_1($pdo);
    }

    $st = $pdo->prepare(
        'INSERT INTO schema_version (id, version) VALUES (1, ?)
         ON DUPLICATE KEY UPDATE version = VALUES(version)'
    );
    $st->execute([SCHEMA_VERSION]);
}

/** Initial schema: taxonomy, assets, images, CTAs and the asset↔category join. */
function schema_step_1(PDO $pdo): void
{
    // Taxonomy. A "filter group" is a facet on the homepage (e.g. "Asset Type"); its
    // categories are the checkable values within it.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS filter_groups (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(120)  NOT NULL,
            slug        VARCHAR(120)  NOT NULL,
            sort_order  INT           NOT NULL DEFAULT 0,
            created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_filter_groups_slug (slug),
            KEY idx_filter_groups_sort (sort_order, name)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Category slugs are unique across ALL groups, not just within one, so a shareable URL can
    // carry bare slugs (?c=brochures,social) without also naming the group.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS filter_categories (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            group_id    INT UNSIGNED NOT NULL,
            name        VARCHAR(120)  NOT NULL,
            slug        VARCHAR(120)  NOT NULL,
            sort_order  INT           NOT NULL DEFAULT 0,
            created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_filter_categories_slug (slug),
            KEY idx_filter_categories_group (group_id, sort_order, name),
            CONSTRAINT fk_categories_group FOREIGN KEY (group_id)
                REFERENCES filter_groups (id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS assets (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title           VARCHAR(200)  NOT NULL,
            slug            VARCHAR(200)  NOT NULL,
            summary         VARCHAR(400)  NOT NULL DEFAULT "",
            body_html       MEDIUMTEXT    NULL,
            body_text       MEDIUMTEXT    NULL,
            status          ENUM("published","draft") NOT NULL DEFAULT "published",
            sort_order      INT           NOT NULL DEFAULT 0,
            created_by_oid  VARCHAR(64)   NOT NULL DEFAULT "",
            updated_by_oid  VARCHAR(64)   NOT NULL DEFAULT "",
            created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_assets_slug (slug),
            KEY idx_assets_browse (status, sort_order, title)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // body_text is the searchable plain-text rendering of body_html, maintained on save so
    // search never has to strip tags across the whole table at query time.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS asset_images (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            asset_id    INT UNSIGNED NOT NULL,
            role        ENUM("featured","gallery") NOT NULL DEFAULT "gallery",
            path        VARCHAR(255)  NOT NULL,
            orig_name   VARCHAR(255)  NOT NULL DEFAULT "",
            mime        VARCHAR(60)   NOT NULL DEFAULT "",
            width       INT UNSIGNED  NOT NULL DEFAULT 0,
            height      INT UNSIGNED  NOT NULL DEFAULT 0,
            sort_order  INT           NOT NULL DEFAULT 0,
            created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_asset_images_asset (asset_id, role, sort_order),
            CONSTRAINT fk_images_asset FOREIGN KEY (asset_id)
                REFERENCES assets (id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS asset_ctas (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            asset_id    INT UNSIGNED NOT NULL,
            label       VARCHAR(120)  NOT NULL,
            url         VARCHAR(1000) NOT NULL,
            style       ENUM("primary","secondary") NOT NULL DEFAULT "primary",
            sort_order  INT           NOT NULL DEFAULT 0,
            KEY idx_asset_ctas_asset (asset_id, sort_order),
            CONSTRAINT fk_ctas_asset FOREIGN KEY (asset_id)
                REFERENCES assets (id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS asset_categories (
            asset_id    INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (asset_id, category_id),
            KEY idx_asset_categories_category (category_id),
            CONSTRAINT fk_ac_asset FOREIGN KEY (asset_id)
                REFERENCES assets (id) ON DELETE CASCADE,
            CONSTRAINT fk_ac_category FOREIGN KEY (category_id)
                REFERENCES filter_categories (id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}
