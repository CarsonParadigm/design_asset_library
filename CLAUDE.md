# CLAUDE.md — Asset Library project journal

This file is Claude's working journal for this project. It holds the standing directives
Claude must follow, plus a running log of every change made to the codebase.

See [CONTEXT.md](CONTEXT.md) for the infrastructure/build context (container, DB, mounts, deploy).

---

## Standing directives

These are permanent instructions from Carson. Follow them on every session, without being re-asked.

### 1. Commit after every change
Every time Carson prompts for a change to the codebase, **commit those changes** once they're made.
One prompt → one commit (or a tight series of logically separate commits). This keeps every
instruction individually revertable — Carson can roll back to any point at will.

- Commit locally; do **not** push unless asked.
- Write a clear, specific subject line describing what changed and why.

### 2. Journal on "push to origin"
Every time Carson says **"push to origin"**, before pushing:

1. Update this file's **Change log** with everything done since the last push.
2. Commit the journal update.
3. Then push to `origin`.

Keep this file current — it's the durable record of how the project got to its present state.

### Git mechanics for this repo
All git commands must run through **`sudo -u poh-svc`** — the deploy key and the
`github-asset-library` SSH alias live in `poh-svc`'s `~/.ssh` and are private to that user.
Running git as any other user fails with `Could not resolve hostname github-asset-library`.

```bash
sudo -u poh-svc -H git -C /home/poh-svc/apps/asset-library status
sudo -u poh-svc -H git -C /home/poh-svc/apps/asset-library commit -m "..."
sudo -u poh-svc -H git -C /home/poh-svc/apps/asset-library push origin main
```

- **Remote:** `origin` → `git@github-asset-library:CarsonParadigm/design_asset_library.git`
- **Branch:** `main`
- **Deploy key:** `/home/poh-svc/.ssh/deploy_asset-library_ed25519` (write-enabled, repo-scoped)
- **Commit identity:** `poh-apps server (poh-svc) <poh-svc@poh-apps.com>` (repo-local config)

---

## Change log

Newest entries at the top.

### 2026-08-17 — v0.1.0: the asset library itself

First real release. Tagged `v0.1.0`; the footer names the tag and its release timestamp
(both live in `src/version.php` — bump them in the same commit that creates a tag).

**Decisions Carson made during the build**
- **Thumbnails:** rebuild the container image with GD rather than serve full-size originals.
- **Upload cap:** 5 MB per image (asked at 10, revised to 2, settled on 5).
- **WYSIWYG:** vendor Quill locally instead of loading from a CDN.
- **Scope:** build *and* deploy live, not just tag and push.
- **Image storage:** Bunny.net Edge Storage with **public** delivery URLs. Raised that
  public Pull Zone URLs bypass the M365 edge — anyone holding a link can fetch the file —
  and that 93 GB free made storage bloat a non-issue at ~11 MB/asset; Carson chose Bunny
  anyway. Built as a swappable `ImageStore`, so this is a config switch, not a rewrite.
  **Still on `local` until credentials exist** (see "Open threads").

**Infrastructure (host-side, outside this repo — each backed up first)**
- `Containerfile`: `poh-dashboard-base` + `gd` (jpeg/png/webp/freetype) and `exif`, plus
  `upload_max_filesize=5M` / `post_max_size=64M` / `memory_limit=256M`. Built as
  `localhost/asset-library:0.1`.
- Quadlet now runs that image and mounts `apps/asset-library-data/uploads` read-write at
  `/var/www/html/data/uploads` (uid 165568, no `:U`). The code mount stays read-only.
- nginx: `client_max_body_size 64m` on the `/asset-library/` location.
- `.env` re-owned `poh-svc:165568` mode 640 — the container's `www-data` could not read it
  otherwise, and the app failed with "Access denied for user ''@'localhost'". Same fix the
  wiki app uses.

**The application**
- Front controller (`index.php`) + `src/` (bootstrap, helpers, auth, schema, repo, images,
  two controllers) + `views/`. No framework, no composer.
- Schema migrations in `src/schema.php`: filter groups/categories, assets, images, CTAs and
  the asset↔category join.
- Public library: server-rendered grid, sidebar facets, live search. The URL carries the
  full state (`?q=&c=`), so any view is shareable; Back/Forward restore filters *and* an
  open asset. Assets deep-link at `/asset/{slug}` and render the modal server-side.
- Asset modal: large image + thumbnail strip, configurable CTAs (always `target=_blank`),
  and the rich-text body with video embeds.
- Admin: filter-group/category management, and one multipart form per asset covering title,
  summary, status, featured image, gallery (≤10), CTA repeater, Quill body and categories.
- Security: uploads are re-encoded through GD (drops EXIF and any embedded payload);
  `sanitize_rich_html()` allowlists tags/attributes and permits `<iframe>` only from known
  video hosts; CSRF on every mutating POST; `/admin` gated on an email allowlist.

**Verified end to end** against the running container: taxonomy CRUD; asset create with a
3000×1800 PNG (resized to 2400×1440) and a JPEG; derivative + thumbnail generation; image
serving; delete cascading to files; facet semantics (OR within a group, AND across groups);
non-admins get 403 on `/admin` and never see the button; the sanitiser dropped a `<script>`,
a `javascript:` href, a `javascript:` CTA and an off-allowlist iframe while keeping a
YouTube embed; the app survives a container restart; the public URL 302s to M365 auth.

**Open threads**
- `IMAGE_STORE` is still `local`. Switching to Bunny needs `BUNNY_STORAGE_ZONE`,
  `BUNNY_STORAGE_KEY`, `BUNNY_STORAGE_HOST` and `BUNNY_PULL_ZONE_HOST` in `.env`.
- Example taxonomy was left in place (Asset Type: Flyer/Poster/Social Post; Service Line:
  Orthodontics/Oral Surgery) as a starting point — rename or delete freely.
- No pagination; no drag-to-reorder for gallery images (the column and helper exist).

### 2026-08-17 — Project initialization
- Generated a repo-scoped, write-enabled deploy key
  (`~poh-svc/.ssh/deploy_asset-library_ed25519`) and registered the public key on GitHub.
- Added the `github-asset-library` SSH host alias to `/home/poh-svc/.ssh/config`
  (`IdentitiesOnly yes`, so git uses only this key for this repo).
- Wired `origin` to `git@github-asset-library:CarsonParadigm/design_asset_library.git`.
- Renamed the initial branch `master` → `main` to match the other POH app repos.
- Set the repo-local commit identity to `poh-apps server (poh-svc)`.
- Created this journal (`CLAUDE.md`) with the two standing directives above.
- Initial commit: existing scaffold (`index.php`, `CONTEXT.md`, `.gitignore`) + this journal.

**Starting state:** empty scaffold from `poh-new-app` — a single `index.php` that prints the
signed-in M365 user. Nothing of the actual asset library is built yet.
