<?php
/**
 * Page chrome shared by every view.
 *
 * The header, footer and palette deliberately mirror /home/poh-svc/areas/design so the app
 * reads as part of the same suite. The one intentional difference is the top-right button,
 * which returns to the design micro-app directory rather than the Paradigm directory.
 *
 * Expects: $pageTitle (string), $bodyClass (string, optional), and $content (callable) —
 * or use layout_start()/layout_end() around inline markup.
 */
declare(strict_types=1);

function layout_start(string $pageTitle, string $bodyClass = '', array $opts = []): void
{
    $useQuill = !empty($opts['quill']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/x-icon" href="<?= e(url('/assets/img/favicon.ico')) ?>">
    <title><?= e($pageTitle) ?> — Paradigm Oral Health</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php if ($useQuill): ?>
    <link rel="stylesheet" href="<?= e(url('/vendor/quill/quill.snow.css')) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>?v=<?= e(APP_VERSION) ?>">
    <script>window.AL_BASE = <?= json_encode(BASE_PATH) ?>;</script>
</head>
<body class="<?= e($bodyClass) ?>">

<header class="app-header">
    <a class="brand" href="<?= e(url('/')) ?>">
        <img class="brand-logo" src="<?= e(url('/assets/img/poh-icon-white.png')) ?>" alt="Paradigm Oral Health">
        <span class="brand-text">
            <span class="brand-name">Paradigm Oral Health</span>
            <span class="brand-sub">Design Asset Library</span>
        </span>
    </a>
    <nav class="header-actions">
        <?php if (is_admin()): ?>
            <?php if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin')): ?>
                <a class="back-link" href="<?= e(url('/')) ?>">View library</a>
            <?php else: ?>
                <a class="back-link" href="<?= e(url('/admin/assets')) ?>">Configure assets</a>
            <?php endif; ?>
        <?php endif; ?>
        <a class="back-link" href="https://design.poh-apps.com">&larr; Design Apps</a>
    </nav>
</header>

<main class="<?= e($opts['mainClass'] ?? '') ?>">
    <?php
}

function layout_end(): void
{
    ?>
</main>

<footer>
    <span>&copy; <?= date('Y') ?> Paradigm Oral Health &mdash; Internal Use Only</span>
    <span class="footer-sep">&middot;</span>
    <span class="footer-version"><?= e(app_version_line()) ?></span>
</footer>

</body>
</html>
    <?php
}
