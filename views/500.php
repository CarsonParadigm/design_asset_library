<?php
/**
 * Generic failure page. The detail is in the container log (error_log in index.php) — never
 * on screen, since anyone in the company can trip this.
 */
declare(strict_types=1);
require_once APP_ROOT . '/views/layout.php';
layout_start('Something went wrong', '', ['mainClass' => 'library']);
?>
<div class="empty-state" style="max-width:520px;margin:60px auto;">
    <div class="empty-emblem">
        <svg viewBox="0 0 24 24"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12" y2="17.1"/></svg>
    </div>
    <h3>Something went wrong</h3>
    <p>The error has been logged. Please try again — if it keeps happening, let the design
       team know what you were doing at the time.</p>
    <a class="btn btn-primary" href="<?= e(url('/')) ?>">Back to the library</a>
</div>
<?php layout_end(); ?>
