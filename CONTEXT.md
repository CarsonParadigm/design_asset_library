# Asset Library — build context

**Status:** v0.1.0 shipped 2026-08-17. Custom PHP app, built and live.

## What it is
A custom **design asset library** at **https://design.poh-apps.com/asset-library/** — browse, search and
manage brand/design files.

Every signed-in employee can browse. Managing assets and the taxonomy is limited to the
addresses in `ADMIN_EMAILS` (`src/auth.php`) — currently Carson only.

## Runtime & deployment
- **Dir:** `/home/poh-svc/apps/asset-library` (custom app; container `app-asset-library`, port 8095).
- **Image:** `localhost/asset-library:0.1` — `poh-dashboard-base` **plus `gd` and `exif`** and this
  app's upload limits, built from the repo's `Containerfile`. The stock base has no image
  extension, so it cannot resize anything.
- **Deploy (code only):** `git pull` — the code is still bind-mounted read-only, so no restart.
- **Deploy (Containerfile changed):**
  `podman build -t localhost/asset-library:0.1 /home/poh-svc/apps/asset-library` then
  `systemctl --user restart asset-library`.
- **Path-mount:** `design.poh-apps.com/asset-library/` (nginx location on the design vhost). The
  trailing slash on `proxy_pass` **strips the prefix**, so PHP sees `/…`; every browser-facing URL
  goes through `url()` in `src/helpers.php`, which adds it back. `client_max_body_size 64m` there
  must stay in step with `post_max_size` in the Containerfile.
- **Uploads volume:** `apps/asset-library-data/uploads` → `/var/www/html/data/uploads` (rw). Host dir
  is owned by **uid/gid 165568** (rootless Podman's mapping of the container's `www-data`); do **not**
  add `:U` to the mount. `data/uploads/` is tracked (with a `.gitkeep`) because Podman cannot create
  a mountpoint inside a read-only bind mount — without it the container fails to start on a fresh
  clone.
- **MySQL:** DB `asset-library` + login `asset-library`@localhost, over the **unix socket**. Password in
  `.env` (gitignored). `.env` must be `poh-svc:165568` mode `640` so the container's `www-data` can
  read it — same trick as `apps/wiki`.
- Behind the **M365 edge** (authenticated employees); identity comes from `/opt/poh/identity.php`.

## How it's built
- No framework, no composer. `index.php` is a front controller; `src/` holds bootstrap, helpers,
  auth, schema, repo and the two controllers; `views/` holds the templates.
- **Schema** is created and migrated in `src/schema.php` on request (cheap version check). Add a new
  numbered step; never edit an applied one.
- **Images** are re-encoded through GD on upload — that drops EXIF and any embedded payload, so a
  polyglot file cannot survive. Two derivatives per image: display (≤2400px) and thumb (≤600px).
- **Image storage is pluggable** (`ImageStore` in `src/images.php`): `local` (default) writes to the
  volume; `bunny` uploads to Bunny.net Edge Storage and serves from a public Pull Zone. Switch with
  `IMAGE_STORE=bunny` plus `BUNNY_STORAGE_ZONE`, `BUNNY_STORAGE_KEY`, `BUNNY_STORAGE_HOST` and
  `BUNNY_PULL_ZONE_HOST` in `.env`. **Bunny URLs are public** — anyone with a link bypasses the M365
  edge. Chosen deliberately; revisit if assets ever need to be genuinely private.
- **WYSIWYG** is vendored Quill 2.0.3 (`vendor/quill/`, no CDN). Output is filtered by
  `sanitize_rich_html()`, which allows `<iframe>` only from the hosts in `VIDEO_EMBED_HOSTS`.
- **Filters** behave the way people expect: OR within a filter group, AND across groups.
- The footer always names the tagged release and when it shipped — bump both constants in
  `src/version.php` in the same commit that creates the tag.

## Still to do
- Point `IMAGE_STORE` at Bunny once the storage zone, API key and pull-zone hostname exist.
- No pagination yet — the grid renders every matching asset. Fine for hundreds; revisit in the
  low thousands.
- Gallery images append in upload order; there is no drag-to-reorder UI yet (the `sort_order`
  column and `asset_image_reorder()` are already there for it).
