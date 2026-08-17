/**
 * Filter-page interactions: drag-to-reorder categories, and a save button that stays inert
 * until something actually changed.
 *
 * Reordering persists on drop via fetch rather than waiting for a save — the row has already
 * moved on screen, so making the user confirm it would only make the change feel slower than
 * it was. The row's own save button is for the *name*, which does need confirming.
 */
(function () {
    'use strict';

    var BASE = window.AL_BASE || '';

    /* ── Save button: enabled only while the row is dirty ─────────────────── */

    document.querySelectorAll('.cat-row[data-cat-id]').forEach(function (row) {
        var input = row.querySelector('.cat-name');
        var save  = row.querySelector('.icon-btn-save');
        if (!input || !save) { return; }

        var pristine = input.value;

        function sync() {
            var dirty = input.value.trim() !== pristine.trim();
            save.disabled = !dirty || input.value.trim() === '';
            row.classList.toggle('is-dirty', dirty);
        }

        input.addEventListener('input', sync);
        // Enter in the field submits the row form, which is the expected shortcut — but only
        // when there is something to save.
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && save.disabled) { ev.preventDefault(); }
        });
        sync();
    });

    /* ── Drag to reorder ──────────────────────────────────────────────────── */

    document.querySelectorAll('.cat-list').forEach(function (list) {
        var groupId = list.dataset.groupId;
        var dragged = null;

        function rows() {
            return Array.prototype.slice.call(list.querySelectorAll('.cat-row[data-cat-id]'));
        }

        /** Persist the current DOM order. */
        function persist() {
            var body = new URLSearchParams();
            body.set('_csrf', csrfToken(list));
            body.set('group_id', groupId);
            rows().forEach(function (r) { body.append('ids[]', r.dataset.catId); });

            list.classList.add('is-saving');
            fetch(BASE + '/admin/filters/category/reorder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body: body.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    list.classList.remove('is-saving');
                    if (!d.ok) { throw new Error(d.error || 'failed'); }
                    flashOrder(list, 'Order saved');
                })
                .catch(function () {
                    list.classList.remove('is-saving');
                    flashOrder(list, 'Could not save the new order — reload and try again.', true);
                });
        }

        /** Which row should the dragged one be inserted before, given the pointer's Y? */
        function rowAfter(y) {
            return rows().reduce(function (closest, row) {
                if (row === dragged) { return closest; }
                var box    = row.getBoundingClientRect();
                var offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: row };
                }
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
        }

        // The handle carries draggable, but the whole row is what moves — so the handle turns
        // its parent row into the drag source for the duration of the gesture.
        list.addEventListener('mousedown', function (ev) {
            var handle = ev.target.closest('.cat-drag');
            if (handle) { handle.closest('.cat-row').draggable = true; }
        });

        list.addEventListener('dragstart', function (ev) {
            var row = ev.target.closest('.cat-row[data-cat-id]');
            if (!row) { return; }
            dragged = row;
            row.classList.add('dragging');
            ev.dataTransfer.effectAllowed = 'move';
            // Firefox will not start a drag without data on the transfer.
            ev.dataTransfer.setData('text/plain', row.dataset.catId);
        });

        list.addEventListener('dragover', function (ev) {
            if (!dragged) { return; }
            ev.preventDefault();
            ev.dataTransfer.dropEffect = 'move';
            var after = rowAfter(ev.clientY);
            if (after) {
                list.insertBefore(dragged, after);
            } else {
                list.appendChild(dragged);
            }
        });

        list.addEventListener('drop', function (ev) { ev.preventDefault(); });

        list.addEventListener('dragend', function () {
            if (!dragged) { return; }
            dragged.classList.remove('dragging');
            dragged.draggable = false;
            dragged = null;
            persist();
        });

        // Keyboard equivalent, so reordering isn't mouse-only.
        list.addEventListener('keydown', function (ev) {
            var handle = ev.target.closest('.cat-drag');
            if (!handle || (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown')) { return; }
            ev.preventDefault();

            var row = handle.closest('.cat-row');
            if (ev.key === 'ArrowUp' && row.previousElementSibling
                && row.previousElementSibling.dataset.catId) {
                list.insertBefore(row, row.previousElementSibling);
            } else if (ev.key === 'ArrowDown' && row.nextElementSibling) {
                list.insertBefore(row.nextElementSibling, row);
            } else {
                return;
            }
            handle.focus();
            persist();
        });
    });

    /** Any CSRF token on the page is valid — they all come from the same session. */
    function csrfToken(scope) {
        var field = (scope && scope.querySelector('input[name="_csrf"]'))
            || document.querySelector('input[name="_csrf"]');
        return field ? field.value : '';
    }

    function flashOrder(list, message, isError) {
        var note = list.parentNode.querySelector('.cat-order-note');
        if (!note) {
            note = document.createElement('p');
            note.className = 'cat-order-note';
            list.parentNode.insertBefore(note, list.nextSibling);
        }
        note.textContent = message;
        note.classList.toggle('is-error', !!isError);
        note.classList.add('show');
        clearTimeout(note._t);
        note._t = setTimeout(function () { note.classList.remove('show'); }, isError ? 6000 : 2000);
    }
}());
