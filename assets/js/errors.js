/**
 * Front-end error reporting.
 *
 * Two jobs, both aimed at the soft launch:
 *   1. Tell the person what went wrong, instead of a control that silently does nothing.
 *   2. Send the same detail back to the server log, because a failure in someone else's
 *      browser otherwise leaves no trace at all.
 *
 * Loaded on every page, before the other scripts, so it can catch their failures too.
 */
window.AL_ERRORS = (function () {
    'use strict';

    var BASE     = window.AL_BASE || '';
    var reported = {};   // dedupe: a loop must not post the same error hundreds of times
    var sent     = 0;
    var MAX_SENT = 20;

    function token() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    /** Post one error to the server log. Never throws — reporting must not cause errors. */
    function report(payload) {
        try {
            var key = (payload.message || '') + '@' + (payload.source || '') + ':' + (payload.line || 0);
            if (reported[key] || sent >= MAX_SENT) { return; }
            reported[key] = true;
            sent++;

            fetch(BASE + '/api/client-error', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': token()
                },
                body: JSON.stringify({
                    kind:    payload.kind || 'error',
                    message: payload.message || '',
                    source:  payload.source || '',
                    line:    payload.line || 0,
                    column:  payload.column || 0,
                    page:    location.pathname + location.search,
                    agent:   navigator.userAgent
                }),
                keepalive: true
            }).catch(function () { /* the log is best-effort; never surface this */ });
        } catch (e) { /* ignore */ }
    }

    /** Visible, dismissible banner. Stacks bottom-right so it cannot cover the page content. */
    function show(title, detail, kind) {
        var host = document.getElementById('al-toasts');
        if (!host) {
            host = document.createElement('div');
            host.id = 'al-toasts';
            host.className = 'al-toasts';
            document.body.appendChild(host);
        }
        var el = document.createElement('div');
        el.className = 'al-toast' + (kind === 'warn' ? ' is-warn' : '');
        el.innerHTML =
            '<div class="al-toast-head"><span>' + esc(title) + '</span>'
          + '<button type="button" aria-label="Dismiss">&times;</button></div>'
          + (detail ? '<p class="al-toast-detail">' + esc(detail) + '</p>' : '');
        el.querySelector('button').addEventListener('click', function () { el.remove(); });
        host.appendChild(el);

        // Warnings fade; hard errors stay until dismissed, so they cannot be missed.
        if (kind === 'warn') {
            setTimeout(function () { el.remove(); }, 7000);
        }
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Uncaught JavaScript errors.
    window.addEventListener('error', function (ev) {
        // Failed <img>/<script> loads also fire this, but with no ev.error and a target.
        if (ev.target && ev.target !== window && ev.target.tagName) {
            var src = ev.target.currentSrc || ev.target.src || ev.target.href || '';
            if (src) {
                report({ kind: 'resource', message: ev.target.tagName + ' failed to load', source: src });
            }
            return;
        }
        report({
            kind: 'js', message: ev.message, source: ev.filename,
            line: ev.lineno, column: ev.colno
        });
        show('Something on this page failed', ev.message, 'error');
    }, true);

    // Promise rejections that nothing handled (most fetch failures land here).
    window.addEventListener('unhandledrejection', function (ev) {
        var msg = (ev.reason && (ev.reason.message || ev.reason)) || 'Unknown error';
        report({ kind: 'promise', message: String(msg) });
        show('Something on this page failed', String(msg), 'error');
    });

    return {
        report: report,
        show: show,

        /**
         * Turn a failed fetch Response into a useful message.
         *
         * The server sends {error, reference} on failure, so the banner can name the actual
         * cause and the reference that ties it to the server log — rather than "try again".
         */
        fromResponse: function (res, fallback) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                var msg = (data && data.error) || fallback || ('Request failed (' + res.status + ')');
                if (data && data.reference) { msg += ' · ref ' + data.reference; }
                var err = new Error(msg);
                err.handled = true;
                throw err;
            });
        },

        /** Report + display in one call, for a failure the caller already caught. */
        fail: function (title, err) {
            var msg = (err && (err.message || err)) || 'Unknown error';
            report({ kind: 'handled', message: title + ': ' + msg });
            show(title, String(msg), 'error');
        }
    };
}());
