<?php
declare(strict_types=1);
require_once APP_ROOT . '/views/layout.php';
layout_start('Not found', '', ['mainClass' => 'library']);
?>
<div class="empty-state" style="max-width:520px;margin:60px auto;">
    <div class="empty-emblem">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="13"/><line x1="12" y1="16.5" x2="12" y2="16.6"/></svg>
    </div>
    <h3>We couldn't find that page</h3>
    <p>The link may be out of date, or the asset it pointed to has been removed.</p>
    <a class="btn btn-primary" href="<?= e(url('/')) ?>">Back to the library</a>
</div>
<?php layout_end(); ?>
