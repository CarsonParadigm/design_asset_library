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
        <?php if (effective_admin()): ?>
            <?php if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin')): ?>
                <a class="back-link" href="<?= e(url('/')) ?>">View library</a>
            <?php else: ?>
                <a class="back-link" href="<?= e(url('/admin/assets')) ?>">Configure assets</a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (is_admin()): ?>
            <?php // Gated on is_admin(), not effective_admin() — the way out of the preview has
                  // to stay visible while the preview is hiding everything else. ?>
            <form method="post" action="<?= e(url('/view-as')) ?>" class="view-as-form">
                <?= csrf_field() ?>
                <input type="hidden" name="mode" value="<?= viewing_as_user() ? 'admin' : 'user' ?>">
                <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
                <button type="submit" class="back-link view-as-toggle <?= viewing_as_user() ? 'is-active' : '' ?>"
                        title="<?= viewing_as_user()
                            ? 'Return to the admin view'
                            : 'Preview the library exactly as a non-admin employee sees it' ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <?php if (viewing_as_user()): ?>
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                            <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                            <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>
                        <?php else: ?>
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        <?php endif; ?>
                    </svg>
                    <?= viewing_as_user() ? 'Exit user view' : 'View as user' ?>
                </button>
            </form>
        <?php endif; ?>

        <a class="back-link" href="https://design.poh-apps.com">&larr; Design Apps</a>
    </nav>
</header>

<?php if (viewing_as_user()): ?>
    <div class="preview-bar">
        <span class="preview-dot" aria-hidden="true"></span>
        You are previewing the library as a non-admin employee. Admin controls are hidden and
        <code>/admin</code> is blocked while this is on.
        <form method="post" action="<?= e(url('/view-as')) ?>" class="preview-bar-form">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="admin">
            <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
            <button type="submit" class="preview-bar-exit">Exit user view</button>
        </form>
    </div>
<?php endif; ?>

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
