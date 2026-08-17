# Reading user identity (O365 / Entra) in this app

Every request to this app is **already authenticated at the edge** — oauth2-proxy (Microsoft 365 /
Entra ID) sits in front via nginx, so by the time your PHP runs the signed-in user is known. You read
their identity from a shared helper; you never implement the OAuth flow yourself.

## The helper — `/opt/poh/identity.php`

One file (`/srv/poh/identity.php` on the host) is bind-mounted read-only into every container at
`/opt/poh/identity.php`, and the base image sets `auto_prepend_file=/opt/poh/identity.php`. So these
functions are **auto-loaded before every request — no `require` needed**. (The file is
`function_exists`-guarded, so an explicit `require '/opt/poh/identity.php';` is also safe if you'd
rather be defensive.)

### Functions

| Function | Returns | Source header |
| --- | --- | --- |
| `poh_user_email()` | email, e.g. `carson.bennett@paradigmoralhealth.com` | `X-Auth-Request-Email` |
| `poh_user_name()` | preferred / display username (falls back to email) | `X-Auth-Request-Preferred-Username` |
| `poh_user_id()` | stable Entra object id (oid) — best user key | `X-Auth-Request-User` |
| `poh_user_groups()` | `array` of Entra group GUIDs | `X-Auth-Request-Groups` |
| `poh_in_group($guid)` | `bool` — is the user in that group GUID | — |
| `poh_is_authenticated()` | `bool` — true if an email is present | — |

### Usage

```php
<?php
// Auto-loaded — just call them.
$email  = poh_user_email();     // '' if somehow unauthenticated
$name   = poh_user_name();
$oid    = poh_user_id();        // use as the user primary key, not the email
$groups = poh_user_groups();

if (!poh_is_authenticated()) {
    http_response_code(401);
    exit('Not authenticated');
}
```

## Why these values are trustworthy

The edge (oauth2-proxy → nginx) **strips any client-supplied `X-Auth-*` headers** and injects its own
only after verifying the live M365 session. So the values above are trustworthy **inside the
container** — but only because the request came through the edge. Never expose this app on a port that
bypasses nginx, and never trust `X-Auth-*` from any other source.

## Notes for building the asset library

- **Store `poh_user_id()` (the Entra oid), not the email, as the stable identifier** for who
  uploaded/owns an asset — emails can change, the oid does not. Keep the email for display.
- **Access control:** every signed-in employee can reach this app by default. For finer control
  (e.g. an "asset admins" group), gate on `poh_in_group('<entra-group-guid>')`, or ask the admin to
  restrict the app in the access directory (`/etc/nginx/conf.d/poh-access.conf`).
- **Fallback** (only if ever running on a non-standard image without the auto-prepend): read the raw
  header — `$_SERVER['HTTP_X_AUTH_REQUEST_EMAIL'] ?? ''`.
- **Source of truth:** `/srv/poh/identity.php` — do not copy it into the app; it's mounted for you and
  edits there propagate to every app on the next request.
