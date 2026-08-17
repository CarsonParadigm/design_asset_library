# Asset Library — build context

**Status:** empty scaffold 2026-08-05. Custom app, **to be built**. Carson is building this.

## What it is
A custom **design asset library** at **https://design.poh-apps.com/asset-library/** — browse, search and
manage brand/design files.

## Already set up
- **Dir:** `/home/poh-svc/apps/asset-library` (custom app; container `app-asset-library`, port 8095).
- **Runtime:** currently **PHP 8.3 + Apache + pdo_mysql** (`poh-dashboard-base` seed). Swap the
  seed/Containerfile if you want a different runtime.
- **Path-mount:** `design.poh-apps.com/asset-library/` (nginx location on the design vhost).
- **MySQL:** DB `asset-library` + login `asset-library`@localhost created. Password in `.env` (gitignored).
  Connect via the **unix socket** (`DB_HOST=localhost`, socket `/var/run/mysqld/mysqld.sock`).
- Behind the **M365 edge** (authenticated employees). Quadlet already standardized (socket **directory**
  mount + memory caps).

## To do when building
- **Add a writable volume for stored assets** — there is none yet, and code is bind-mounted `:ro`, so
  uploads must go to a writable volume. Either a data dir (`apps/asset-library-data` ->
  `/var/www/html/data`, rw, owned by container www-data / subuid 165568) or read/write the shared drive.
- Build the app; wire up git (its own repo) independently.
- Deploy = `git pull` + `systemctl --user restart asset-library.service` (rebuild if the image changes).
