<?php
// Identity helper is baked into the POH seeds at /opt/poh/identity.php (outside the
// code mount, so it's always available). Use it when present; fall back to the raw
// trusted header otherwise (e.g. a custom non-seed image).
$idf = '/opt/poh/identity.php';
if (is_file($idf)) { require $idf; $email = poh_user_email(); }
else { $email = $_SERVER['HTTP_X_AUTH_REQUEST_EMAIL'] ?? 'unknown'; }
?>
<!doctype html><meta charset="utf-8">
<title>asset-library — POH Apps</title>
<h1>asset-library</h1>
<p>Scaffolded by poh-new-app. Replace this with your app.</p>
<p>Signed in as: <code><?= htmlspecialchars($email) ?></code></p>
