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

### 2026-08-21 — v1.0.0: soft launch

Tagged `v1.0.0`; footer bumped to match (`src/version.php`).

**Filters page — categories reworked** (`96b6652`)
- Drag a row by its grip handle to reorder; the new order saves on drop via a JSON endpoint
  rather than waiting for a save. Arrow keys on a focused handle do the same.
- The per-row sort-order number input is gone — position is expressed by dragging, and new
  categories append to the end.
- Save is a check icon, disabled until the name actually differs from what loaded. Delete is
  a trash icon at the far right, posting to its own form via the `form` attribute (forms
  cannot nest inside the row form).
- `category_reorder()` scopes its UPDATE to the group being dragged, so a tampered payload
  cannot move a category between groups.
- Fixed two things found while testing: `csrf_check()` answered **419**, which Apache does not
  recognise and rewrites to a 500, so a rejected request looked like a server fault (now 403);
  and `hash_equals('', '')` is true, so a request sending no token was accepted whenever the
  session had not yet issued one.

**Four more admins, plus a "View as user" toggle** (`9e9c4b9`)
- Natalie Radbill, Sammi Meyers, Bridget Fuessel and Alanna Warren joined `ADMIN_EMAILS`.
- The old single admin check is now two: `is_admin()` (who you are) and `effective_admin()`
  (what you should see right now). Every UI and authorisation decision asks the latter, so a
  previewing admin is genuinely refused `/admin` rather than just having buttons hidden.
- The toggle is gated on `is_admin()`, and `/view-as` is routed **outside** the `/admin` gate —
  otherwise enabling the preview would make the way out unreachable.
- The `return` parameter is untrusted: absolute and protocol-relative URLs are refused, and
  anything under `/admin` falls back to the library root.

**Bug: saving an asset hung the browser** (`f9698e7`) — reported by Bridget
- Not the app. nginx size-checks the oauth2 `auth_request` subrequest against
  `location = /oauth2/auth`, which had no `client_max_body_size` and so inherited the **1m**
  default. Bridget's 3.7 MB form made that subrequest fail 413 → `auth request unexpected
  status` → nginx emitted a 500 mid-upload, which the browser shows as a request that never
  finishes. The app's own 64m limit never came into it.
- Fixed host-side in `snippets/o365-auth-enabled.conf` (backed up first) with
  `client_max_body_size 0` on the auth location — safe, because `proxy_pass_request_body off`
  means the body never reaches oauth2-proxy. **Server-wide fix: the wiki had the same latent
  bug above 1 MB.**
- Also: PHP was running the base image's development defaults (`display_errors=On`,
  `log_errors=Off`), leaking warnings into pages while logging nothing — and a startup warning
  prints before app code runs, flushing headers and pinning the response to 200, so a rejected
  request reported as success. Now logged, never displayed.
- Also: a POST over `post_max_size` leaves `$_POST` empty, so the CSRF check told the user
  their session had expired — sending them to clear cookies, exactly what Bridget tried. Now
  detected and answered 413 naming the real limit.

**Error reporting for the soft launch** (`3d9d73b`)
- `src/errors.php`, installed before the DB or session so a failure in either still reports.
  Every error is logged with a short reference (`AL-XXXXXX`) shown on screen too, so a user's
  screenshot can be found in the log. Renders HTML or JSON to match the request.
- Non-fatal PHP notices are collected into a panel at the foot of the page.
- `APP_DEBUG=1` in `.env` shows the real reason to every user during the soft launch; with it
  off, admins still get full detail and others get the reference. **Turn this off once the
  launch settles.**
- `assets/js/errors.js` (loaded first) catches uncaught errors and rejected promises, shows a
  dismissible banner naming the actual failure, and POSTs it to `/api/client-error` so
  browser-side failures reach the container log. Deduped, capped at 20 per page.
- Fixed a bug the tests caught in the oversized-POST guard: it keyed on an empty `$_POST`, but
  PHP only fills that for form content types, so every JSON request looked oversized.

**Sample content**
- Three sample assets (Practice Opening Announcement, Provider Introduction, Referral Program
  Poster) using a recreated "EXAMPLE" stamp image — the pasted original could not be written to
  disk, so it was redrawn with GD using the host's DejaVu font. Each body says it is placeholder
  content. Delete freely.

**Still open**
- `IMAGE_STORE` remains `local`. Carson chose Bunny.net with public URLs; switching needs
  `BUNNY_STORAGE_ZONE`, `BUNNY_STORAGE_KEY`, `BUNNY_STORAGE_HOST` and `BUNNY_PULL_ZONE_HOST`.
  Note the switch is **not retroactive** — existing images would need copying to Bunny first,
  since the DB stores relative paths the backend turns into URLs at render time.
- Other apps on this host still run the stock `display_errors=On`.
- No pagination; no drag-to-reorder for gallery images.

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
