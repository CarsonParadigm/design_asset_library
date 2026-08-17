/**
 * Asset form behaviour: the Quill editor, the CTA repeater, and upload guardrails.
 *
 * The guardrails here are convenience only — every limit is enforced again server-side in
 * admin_asset_save()/image_ingest(). Catching an oversized file before the upload starts just
 * saves the user a slow round trip that ends in an error.
 */
window.ASSET_ADMIN = (function () {
    'use strict';

    function initEditor() {
        var host = document.getElementById('body-editor');
        var sink = document.getElementById('body_html');
        if (!host || !sink || typeof Quill === 'undefined') { return null; }

        var quill = new Quill(host, {
            theme: 'snow',
            placeholder: 'Usage notes, sizing guidance, brand rules, a walkthrough video…',
            modules: {
                toolbar: [
                    [{ header: [2, 3, 4, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['blockquote', 'code-block'],
                    ['link', 'video'],
                    [{ align: [] }],
                    ['clean']
                ]
            }
        });

        // The form posts a plain field, so mirror the editor's HTML into it on submit.
        var form = document.getElementById('asset-form');
        if (form) {
            form.addEventListener('submit', function () {
                // An "empty" Quill still contains <p><br></p>; store nothing rather than that.
                sink.value = quill.getLength() <= 1 ? '' : quill.root.innerHTML;
            });
        }
        return quill;
    }

    function initCtaRepeater() {
        var rows = document.getElementById('cta-rows');
        var add  = document.getElementById('cta-add');
        if (!rows || !add) { return; }

        add.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'repeater-row';
            row.innerHTML =
                '<input type="text" name="cta_label[]" placeholder="Button text" maxlength="120">'
              + '<input type="url" name="cta_url[]" placeholder="https://…" maxlength="1000">'
              + '<select name="cta_style[]" style="width:130px;">'
              + '<option value="primary">Primary</option>'
              + '<option value="secondary">Secondary</option></select>'
              + '<button type="button" class="icon-btn cta-remove" aria-label="Remove button">&times;</button>';
            rows.appendChild(row);
            row.querySelector('input').focus();
        });

        rows.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.cta-remove');
            if (btn) { btn.closest('.repeater-row').remove(); }
        });
    }

    /**
     * Warn about files that would be rejected server-side, and keep the "slots remaining"
     * hint honest as images are ticked for removal.
     */
    function initUploadGuards(cfg) {
        var featured = document.getElementById('featured_image');
        var gallery  = document.getElementById('gallery_images');
        var room     = document.getElementById('gallery-room');

        function tooBig(input) {
            var over = Array.prototype.filter.call(input.files || [], function (f) {
                return f.size > cfg.maxBytes;
            });
            if (over.length) {
                alert(
                    'These files are larger than the ' + cfg.maxBytesLabel + ' limit and would be '
                    + 'rejected:\n\n' + over.map(function (f) { return '• ' + f.name; }).join('\n')
                    + '\n\nPlease resize or export them smaller.'
                );
                input.value = '';
                return true;
            }
            return false;
        }

        if (featured) {
            featured.addEventListener('change', function () { tooBig(featured); });
        }

        function remaining() {
            var removing = document.querySelectorAll('input[name="delete_image[]"]:checked').length;
            return Math.max(0, cfg.maxGallery - cfg.usedGallery + removing);
        }

        function updateRoom() {
            if (!room) { return; }
            var n = remaining();
            room.textContent = n + ' slot' + (n === 1 ? '' : 's') + ' remaining.';
        }

        document.addEventListener('change', function (ev) {
            if (ev.target.name === 'delete_image[]') { updateRoom(); }
        });

        if (gallery) {
            gallery.addEventListener('change', function () {
                if (tooBig(gallery)) { return; }
                var n = remaining();
                if (gallery.files.length > n) {
                    alert(
                        'You selected ' + gallery.files.length + ' images but only ' + n + ' slot'
                        + (n === 1 ? '' : 's') + ' remain (limit is ' + cfg.maxGallery + ').\n\n'
                        + 'Remove some existing gallery images first, or select fewer files.'
                    );
                    gallery.value = '';
                }
            });
        }

        updateRoom();
    }

    return {
        init: function (cfg) {
            initEditor();
            initCtaRepeater();
            initUploadGuards(cfg || { maxGallery: 10, usedGallery: 0, maxBytes: 5242880, maxBytesLabel: '5 MB' });
        }
    };
}());
