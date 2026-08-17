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
