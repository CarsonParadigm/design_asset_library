<?php
/**
 * Error capture and reporting.
 *
 * During the soft launch the priority is that a failure explains itself rather than showing a
 * shrug, so every error gets:
 *   - a full entry in the container log (always),
 *   - a short reference code the user can quote,
 *   - and, when detail is enabled, the actual reason on screen.
 *
 * Note this deliberately does NOT use PHP's display_errors. That setting emits raw warnings
 * before the application can set a status line — which is how a rejected upload was once
 * reported to the browser as a 200 — and it leaks filesystem paths to whoever tripped it.
 * Everything here is rendered by us, after we decide the status, at a detail level we choose.
 */
declare(strict_types=1);

/** Non-fatal diagnostics collected during this request, shown in the debug panel. */
$GLOBALS['al_notices'] = [];

/**
 * Should this request be told what actually went wrong?
 *
 * Admins always get the detail. APP_DEBUG=1 in .env extends that to everyone, which is what
 * we want while the whole company is bug-hunting a soft launch — turn it off once the traffic
 * is ordinary users who can only be confused by a stack frame.
 */
function error_detail_visible(): bool
{
    if (env('APP_DEBUG', '0') === '1') {
        return true;
    }
    return function_exists('is_admin') && is_admin();
}

/** Short, quotable reference that ties the on-screen message to the log entry. */
function error_reference(): string
{
    static $ref = null;
    return $ref ??= 'AL-' . strtoupper(bin2hex(random_bytes(3)));
}

function wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr    = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    return $xhr
        || str_contains($accept, 'application/json')
        || str_contains($path, '/api/');
}

/** Install the handlers. Called once, early, from bootstrap.php. */
function errors_install(): void
{
    // Warnings and notices: record them, never let them reach the output stream.
    set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
        if (!(error_reporting() & $no)) {
            return false; // Suppressed with @ — respect that.
        }
        $label = match ($no) {
            E_WARNING, E_USER_WARNING         => 'Warning',
            E_NOTICE, E_USER_NOTICE           => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED   => 'Deprecated',
            default                           => 'Error',
        };
        error_log(sprintf(
            'asset-library[%s] %s: %s @ %s:%d',
            error_reference(), $label, $msg, $file, $line
        ));
        $GLOBALS['al_notices'][] = [
            'label' => $label,
            'msg'   => $msg,
            'file'  => $file,
            'line'  => $line,
        ];
        return true; // Handled — do not fall through to PHP's own printer.
    });

    set_exception_handler(static function (Throwable $e): void {
        render_error($e);
    });

    // Fatals bypass the exception handler; catch them on the way out.
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            render_error(new ErrorException(
                $err['message'], 0, $err['type'], $err['file'], $err['line']
            ), true);
        }
    });
}

/**
 * Log an exception and render it for whoever asked.
 *
 * @param bool $isFatal true when called from the shutdown handler, where output may already
 *                      have begun and the status can no longer be changed.
 */
function render_error(Throwable $e, bool $isFatal = false): void
{
    $ref = error_reference();

    error_log(sprintf(
        "asset-library[%s] %s: %s @ %s:%d\n%s",
        $ref, $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
    ));

    $detail  = error_detail_visible();
    $headers = !headers_sent();

    if (wants_json()) {
        if ($headers) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'        => false,
            'reference' => $ref,
            'error'     => $detail ? $e->getMessage() : 'Something went wrong on the server.',
            'detail'    => $detail ? [
                'type' => $e::class,
                'file' => str_replace(APP_ROOT . '/', '', $e->getFile()),
                'line' => $e->getLine(),
            ] : null,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($headers) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    // Rendered inline rather than through the layout: the layout needs the database, and the
    // thing that just failed may well have been the database.
    $refHtml = htmlspecialchars($ref, ENT_QUOTES);
    $home    = htmlspecialchars(function_exists('url') ? url('/') : '/', ENT_QUOTES);
    $body    = '';

    if ($detail) {
        $body = '<div class="err-detail">'
            . '<div class="err-type">' . htmlspecialchars($e::class, ENT_QUOTES) . '</div>'
            . '<p class="err-msg">' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>'
            . '<p class="err-loc">'
            . htmlspecialchars(str_replace(APP_ROOT . '/', '', $e->getFile()), ENT_QUOTES)
            . ' line ' . $e->getLine() . '</p></div>';
    }

    $intro = $detail
        ? 'Here is what went wrong. Please send this to Carson so it can be fixed.'
        : 'The error has been logged. Please send the reference below to Carson so it can be looked up.';

    echo <<<HTML
<!doctype html><meta charset="utf-8"><title>Error — Asset Library</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
 body{font-family:'Source Sans 3',system-ui,sans-serif;background:#f0ece3;color:#1a1a1a;margin:0;
      display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}
 .box{background:#fff;border:1px solid #ddd6c8;border-radius:8px;padding:34px;max-width:620px;
      width:100%;box-shadow:0 2px 12px rgba(11,60,97,.07)}
 h1{font-family:Georgia,serif;color:#0B3C61;font-size:1.35rem;margin:0 0 8px}
 p{color:#6b6560;line-height:1.6;margin:0 0 16px;font-size:.95rem}
 .err-detail{background:#fbeceb;border:1px solid #edcecb;border-radius:6px;padding:14px 16px;margin:0 0 18px}
 .err-type{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#8d2f26;margin-bottom:6px}
 .err-msg{color:#8d2f26;font-weight:600;margin:0 0 6px;font-size:.95rem}
 .err-loc{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;color:#a15c55;margin:0}
 .ref{font-family:ui-monospace,Menlo,Consolas,monospace;background:#f4f1ea;border:1px solid #e8e1d3;
      border-radius:5px;padding:7px 12px;display:inline-block;font-size:14px;font-weight:700;color:#0B3C61}
 a{display:inline-block;background:#C7893C;color:#fff;text-decoration:none;padding:9px 22px;
   border-radius:6px;font-weight:600;font-size:13px;margin-top:18px}
</style>
<div class="box">
  <h1>Something went wrong</h1>
  <p>{$intro}</p>
  {$body}
  <p>Reference: <span class="ref">{$refHtml}</span></p>
  <a href="{$home}">Back to the library</a>
</div>
HTML;
    exit;
}

/**
 * Diagnostics panel appended to a page that otherwise rendered fine.
 *
 * Warnings that do not stop the request are exactly the ones that get missed, so during the
 * soft launch they are surfaced rather than left in the log for nobody to read.
 */
function render_notice_panel(): void
{
    $notices = $GLOBALS['al_notices'] ?? [];
    if (!$notices || !error_detail_visible()) {
        return;
    }
    echo '<div class="debug-panel"><div class="debug-panel-head">'
        . count($notices) . ' PHP ' . (count($notices) === 1 ? 'notice' : 'notices')
        . ' on this page <span>ref ' . htmlspecialchars(error_reference(), ENT_QUOTES) . '</span>'
        . '<button type="button" onclick="this.closest(&quot;.debug-panel&quot;).remove()">Dismiss</button>'
        . '</div><ul>';
    foreach ($notices as $n) {
        echo '<li><strong>' . htmlspecialchars($n['label'], ENT_QUOTES) . ':</strong> '
            . htmlspecialchars($n['msg'], ENT_QUOTES)
            . ' <span>' . htmlspecialchars(str_replace(APP_ROOT . '/', '', $n['file']), ENT_QUOTES)
            . ':' . $n['line'] . '</span></li>';
    }
    echo '</ul></div>';
}
