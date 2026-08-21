<?php
/**
 * Who is signed in, and who may administer the library.
 *
 * Authentication itself is not this app's job: oauth2-proxy (M365 / Entra) sits in front at
 * the nginx edge and injects X-Auth-Request-* headers, which the shared helper at
 * /opt/poh/identity.php exposes. The edge strips any client-supplied X-Auth-* headers first,
 * so those values are trustworthy inside the container. See identity.md.
 *
 * Every authenticated employee can browse the library; only the addresses in ADMIN_EMAILS can
 * reach /admin and change anything.
 */
declare(strict_types=1);

// Normally auto-prepended by the base image (auto_prepend_file). Required defensively so the
// app still works if that ini setting is ever dropped; the helper is function_exists-guarded.
if (!function_exists('poh_user_email') && is_file('/opt/poh/identity.php')) {
    require_once '/opt/poh/identity.php';
}

/**
 * Addresses permitted to administer the library. Compared case-insensitively.
 *
 * To add someone, add their address here and deploy. If this ever grows beyond a handful of
 * people, gate on an Entra group with poh_in_group('<guid>') instead of maintaining a list.
 */
const ADMIN_EMAILS = [
    'carson.bennett@paradigmoralhealth.com',
    'natalie.radbill@paradigmoralhealth.com',
    'sammi.meyers@paradigmoralhealth.com',
    'bridget.fuessel@paradigmoralhealth.com',
    'alanna.warren@paradigmoralhealth.com',
];

function current_email(): string
{
    if (function_exists('poh_user_email')) {
        return (string) poh_user_email();
    }
    return (string) ($_SERVER['HTTP_X_AUTH_REQUEST_EMAIL'] ?? '');
}

function current_name(): string
{
    if (function_exists('poh_user_name')) {
        $n = (string) poh_user_name();
        if ($n !== '') {
            return $n;
        }
    }
    return current_email();
}

/** Stable Entra object id — the right key for "who created this", since emails can change. */
function current_user_id(): string
{
    if (function_exists('poh_user_id')) {
        $id = (string) poh_user_id();
        if ($id !== '') {
            return $id;
        }
    }
    return (string) ($_SERVER['HTTP_X_AUTH_REQUEST_USER'] ?? '');
}

function poh_is_authenticated_safe(): bool
{
    if (function_exists('poh_is_authenticated')) {
        return (bool) poh_is_authenticated();
    }
    return current_email() !== '';
}

// Provide the canonical name when the shared helper is unavailable, so bootstrap.php can call
// poh_is_authenticated() unconditionally.
if (!function_exists('poh_is_authenticated')) {
    function poh_is_authenticated(): bool
    {
        return poh_is_authenticated_safe();
    }
}

/**
 * Session key holding the "view as user" preference.
 *
 * Deliberately session-scoped rather than persisted: previewing the non-admin view is a
 * momentary check, and it should never outlive the browser session and quietly leave someone
 * wondering where their admin controls went.
 */
const VIEW_AS_USER_KEY = 'view_as_user';

/** Is the signed-in person on the admin allowlist? Ignores the view-as-user preview. */
function is_admin(): bool
{
    $email = strtolower(trim(current_email()));
    if ($email === '') {
        return false;
    }
    foreach (ADMIN_EMAILS as $allowed) {
        if (hash_equals(strtolower($allowed), $email)) {
            return true;
        }
    }
    return false;
}

/** Is an admin currently previewing the library as an ordinary employee? */
function viewing_as_user(): bool
{
    return is_admin() && !empty($_SESSION[VIEW_AS_USER_KEY]);
}

/**
 * Does the current request get admin treatment?
 *
 * This is the check every UI and authorisation decision should use — is_admin() answers "who
 * are you", this answers "what should you see right now". While previewing, an admin is
 * treated exactly like an ordinary employee, including being refused /admin, so the preview
 * reflects reality instead of approximating it.
 */
function effective_admin(): bool
{
    return is_admin() && !viewing_as_user();
}

/** Turn the preview on or off. Only a real admin can do either. */
function set_view_as_user(bool $on): void
{
    if (!is_admin()) {
        return;
    }
    if ($on) {
        $_SESSION[VIEW_AS_USER_KEY] = true;
    } else {
        unset($_SESSION[VIEW_AS_USER_KEY]);
    }
}

/** Guard for every /admin route. Renders a plain 403 rather than redirecting into a loop. */
function require_admin(): void
{
    if (effective_admin()) {
        return;
    }
    http_response_code(403);
    $email = e(current_email());
    $home  = e(url('/'));
    echo <<<HTML
    <!doctype html><meta charset="utf-8"><title>Not authorised — Asset Library</title>
    <style>
      body{font-family:'Source Sans 3',system-ui,sans-serif;background:#f0ece3;color:#1a1a1a;
           display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
      .box{background:#fff;border:1px solid #ddd6c8;border-radius:8px;padding:40px;max-width:460px;
           text-align:center;box-shadow:0 2px 12px rgba(11,60,97,.07)}
      h1{font-family:Georgia,serif;color:#0B3C61;font-size:1.3rem;margin:0 0 10px}
      p{color:#6b6560;line-height:1.55;margin:0 0 20px;font-size:.95rem}
      a{display:inline-block;background:#C7893C;color:#fff;text-decoration:none;padding:9px 22px;
        border-radius:6px;font-weight:600;font-size:13px}
    </style>
    <div class="box">
      <h1>You don't have access to this area</h1>
      <p>Managing assets is limited to the design library administrators.
         You're signed in as <strong>$email</strong>.</p>
      <a href="$home">Back to the library</a>
    </div>
    HTML;
    exit;
}
