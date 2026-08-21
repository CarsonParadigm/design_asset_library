/**
 * Library page behaviour: live search, faceted filtering, shareable URLs, and the asset modal.
 *
 * The server renders the first paint from the query string, so this script starts from
 * window.AL_STATE rather than re-deriving it. From then on every interaction updates the URL
 * (history.pushState) before fetching, which keeps "copy the link" working for any view —
 * including an open asset, which lives at /asset/{slug}.
 */
(function () {
    'use strict';

    var BASE  = window.AL_BASE || '';
    var state = window.AL_STATE || { q: '', categories: [], openAsset: null };

    var searchInput = document.getElementById('asset-search');
    var searchClear = document.getElementById('search-clear');
    var clearAll    = document.getElementById('clear-filters');
    var grid        = document.getElementById('asset-grid');
    var chipWrap    = document.getElementById('active-chips');
    var countEl     = document.getElementById('result-count');
    var modal       = document.getElementById('asset-modal');
    var modalPanel  = document.getElementById('modal-panel');
    var checkboxes  = Array.prototype.slice.call(document.querySelectorAll('.filter-cb'));

    var query       = { q: state.q || '', categories: (state.categories || []).slice() };
    var lastFocused = null;
    var fetchSeq    = 0;

    /* ── helpers ──────────────────────────────────────────────────────────── */

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** The browse URL for the current filter state (no asset open). */
    function gridUrl() {
        var params = new URLSearchParams();
        if (query.q) { params.set('q', query.q); }
        if (query.categories.length) { params.set('c', query.categories.join(',')); }
        var qs = params.toString();
        return (BASE || '/') + (qs ? '?' + qs : '');
    }

    function apiUrl() {
        var params = new URLSearchParams();
        if (query.q) { params.set('q', query.q); }
        if (query.categories.length) { params.set('c', query.categories.join(',')); }
        return BASE + '/api/assets?' + params.toString();
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(null, args); }, ms);
        };
    }

    /* ── grid ─────────────────────────────────────────────────────────────── */

    function renderChips() {
        if (!chipWrap) { return; }
        chipWrap.innerHTML = query.categories.map(function (slug) {
            var cb    = checkboxes.filter(function (c) { return c.value === slug; })[0];
            var label = cb ? cb.parentNode.querySelector('.filter-option-name').textContent : slug;
            return '<button type="button" class="chip" data-slug="' + esc(slug) + '">'
                 + esc(label) + '<span class="x">&times;</span></button>';
        }).join('');
        if (clearAll) { clearAll.hidden = query.categories.length === 0; }
    }

    function renderGrid(assets) {
        if (!assets.length) {
            var filtered = query.q !== '' || query.categories.length > 0;
            grid.innerHTML =
                '<div class="empty-state">'
                + '<div class="empty-emblem"><svg viewBox="0 0 24 24">'
                + '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>'
                + '</svg></div>'
                + '<h3>' + (filtered ? 'No assets match those filters' : 'The library is empty') + '</h3>'
                + '<p>' + (filtered
                    ? 'Try removing a filter or searching for a broader term.'
                    : 'No assets have been published yet.') + '</p>'
                + (filtered
                    ? '<button type="button" class="btn btn-secondary" id="empty-reset">Clear filters</button>'
                    : '')
                + '</div>';
            return;
        }

        grid.innerHTML = assets.map(function (a) {
            var thumb = a.thumb_url
                ? '<img src="' + esc(a.thumb_url) + '" alt="" loading="lazy">'
                : '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/>'
                  + '<circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
            return '<button type="button" class="asset-card" data-slug="' + esc(a.slug) + '">'
                 + '<div class="asset-thumb' + (a.thumb_url ? '' : ' is-empty') + '">' + thumb + '</div>'
                 + '<div class="asset-card-body">'
                 + '<span class="asset-card-title">' + esc(a.title) + '</span>'
                 + (a.summary ? '<span class="asset-card-summary">' + esc(a.summary) + '</span>' : '')
                 + '</div></button>';
        }).join('');
    }

    function refresh(pushUrl) {
        var seq = ++fetchSeq;

        if (pushUrl !== false) {
            history.pushState({ view: 'grid' }, '', gridUrl());
        }
        renderChips();

        fetch(apiUrl(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) { return window.AL_ERRORS.fromResponse(r, 'Could not load assets'); }
                return r.json();
            })
            .then(function (data) {
                // A slower earlier request must not overwrite a newer result.
                if (seq !== fetchSeq) { return; }
                renderGrid(data.assets || []);
                if (countEl) {
                    countEl.textContent = data.total + (data.total === 1 ? ' asset' : ' assets');
                }
            })
            .catch(function (err) {
                if (seq !== fetchSeq) { return; }
                window.AL_ERRORS.fail('Could not load assets', err);
                grid.innerHTML = '<div class="empty-state"><h3>Couldn\u2019t load assets</h3>'
                    + '<p>' + String((err && err.message) || 'Unknown error') + '</p></div>';
            });
    }

    /* ── modal ────────────────────────────────────────────────────────────── */

    function renderModal(a) {
        var images = a.images || [];
        var stage  = images.length
            ? '<img id="stage-img" src="' + esc(images[0].url) + '" alt="' + esc(a.title) + '">'
            : '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/>'
              + '<circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';

        var thumbs = images.length > 1
            ? '<div class="gallery-thumbs">' + images.map(function (img, i) {
                return '<button type="button" class="gallery-thumb' + (i === 0 ? ' active' : '') + '"'
                     + ' data-full="' + esc(img.url) + '" aria-label="View image ' + (i + 1) + '">'
                     + '<img src="' + esc(img.thumb_url) + '" alt=""></button>';
              }).join('') + '</div>'
            : '';

        var ctas = (a.ctas || []).length
            ? '<div class="cta-stack">' + a.ctas.map(function (c) {
                var cls = c.style === 'secondary' ? 'btn btn-secondary' : 'btn btn-primary';
                return '<a class="' + cls + '" href="' + esc(c.url) + '" target="_blank" rel="noopener noreferrer">'
                     + esc(c.label) + '</a>';
              }).join('') + '</div>'
            : '';

        var tags = (a.categories || []).length
            ? '<div class="modal-meta">' + a.categories.map(function (c) {
                return '<span class="tag">' + esc(c.name) + '</span>';
              }).join('') + '</div>'
            : '';

        // body_html is sanitised server-side (see sanitize_rich_html) — inserted as markup so
        // formatting and video embeds render.
        var body = a.body_html
            ? '<div><div class="modal-section-label">Details</div>'
              + '<div class="rich-text">' + a.body_html + '</div></div>'
            : '';

        modalPanel.innerHTML =
            '<div class="modal-head">'
          + '<div><div class="modal-eyebrow">Design Asset</div>'
          + '<h2 class="modal-title" id="modal-title">' + esc(a.title) + '</h2></div>'
          + '<button type="button" class="modal-close" aria-label="Close">&times;</button>'
          + '</div>'
          + '<div class="modal-body">'
          + '<div class="modal-gallery">'
          + '<div class="gallery-stage' + (images.length ? '' : ' is-empty') + '">' + stage + '</div>'
          + thumbs + '</div>'
          + '<div class="modal-detail">'
          + (a.summary ? '<p class="page-subtitle">' + esc(a.summary) + '</p>' : '')
          + ctas + tags + body
          + '</div></div>';
    }

    function openModal(a, pushUrl) {
        renderModal(a);
        modal.hidden = false;
        // Next frame, so the opacity transition has a starting value to animate from.
        requestAnimationFrame(function () { modal.classList.add('open'); });
        document.body.style.overflow = 'hidden';

        if (pushUrl !== false) {
            history.pushState({ view: 'asset', slug: a.slug }, '', BASE + '/asset/' + a.slug);
        }
        var close = modalPanel.querySelector('.modal-close');
        if (close) { close.focus(); }
    }

    function closeModal(pushUrl) {
        modal.classList.remove('open');
        document.body.style.overflow = '';
        setTimeout(function () {
            if (!modal.classList.contains('open')) {
                modal.hidden = true;
                modalPanel.innerHTML = '';
            }
        }, 200);

        if (pushUrl !== false) {
            history.pushState({ view: 'grid' }, '', gridUrl());
        }
        if (lastFocused && document.contains(lastFocused)) { lastFocused.focus(); }
    }

    function loadAsset(slug, pushUrl) {
        modal.hidden = false;
        requestAnimationFrame(function () { modal.classList.add('open'); });
        modalPanel.innerHTML = '<div class="modal-loading">Loading…</div>';
        document.body.style.overflow = 'hidden';

        fetch(BASE + '/api/asset/' + encodeURIComponent(slug), { headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) { return window.AL_ERRORS.fromResponse(r, 'This asset could not be opened'); }
                return r.json();
            })
            .then(function (a) { openModal(a, pushUrl); })
            .catch(function (err) {
                var msg = (err && err.message) || 'This asset may have been removed.';
                window.AL_ERRORS.report({ kind: 'handled', message: 'open asset: ' + msg });
                modalPanel.innerHTML =
                    '<div class="modal-head"><div><h2 class="modal-title">Asset unavailable</h2></div>'
                  + '<button type="button" class="modal-close" aria-label="Close">&times;</button></div>'
                  + '<div class="modal-loading">' + msg + '</div>';
            });
    }

    /* ── events ───────────────────────────────────────────────────────────── */

    if (searchInput) {
        var onSearch = debounce(function () {
            query.q = searchInput.value.trim();
            if (searchClear) { searchClear.hidden = query.q === ''; }
            refresh();
        }, 220);
        searchInput.addEventListener('input', onSearch);
    }

    if (searchClear) {
        searchClear.addEventListener('click', function () {
            searchInput.value = '';
            query.q = '';
            searchClear.hidden = true;
            searchInput.focus();
            refresh();
        });
    }

    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (cb.checked) {
                if (query.categories.indexOf(cb.value) === -1) { query.categories.push(cb.value); }
            } else {
                query.categories = query.categories.filter(function (s) { return s !== cb.value; });
            }
            refresh();
        });
    });

    if (clearAll) {
        clearAll.addEventListener('click', function () {
            query.categories = [];
            checkboxes.forEach(function (cb) { cb.checked = false; });
            refresh();
        });
    }

    if (chipWrap) {
        chipWrap.addEventListener('click', function (ev) {
            var chip = ev.target.closest('.chip');
            if (!chip) { return; }
            var slug = chip.dataset.slug;
            query.categories = query.categories.filter(function (s) { return s !== slug; });
            checkboxes.forEach(function (cb) { if (cb.value === slug) { cb.checked = false; } });
            refresh();
        });
    }

    grid.addEventListener('click', function (ev) {
        var reset = ev.target.closest('#empty-reset');
        if (reset) {
            query.q = '';
            query.categories = [];
            if (searchInput) { searchInput.value = ''; }
            if (searchClear) { searchClear.hidden = true; }
            checkboxes.forEach(function (cb) { cb.checked = false; });
            refresh();
            return;
        }
        var card = ev.target.closest('.asset-card');
        if (!card) { return; }
        lastFocused = card;
        loadAsset(card.dataset.slug);
    });

    modal.addEventListener('click', function (ev) {
        // Backdrop click closes; clicks inside the panel do not.
        if (ev.target === modal || ev.target.closest('.modal-close')) {
            closeModal();
            return;
        }
        var thumb = ev.target.closest('.gallery-thumb');
        if (thumb) {
            var stageImg = modalPanel.querySelector('#stage-img');
            if (stageImg) { stageImg.src = thumb.dataset.full; }
            modalPanel.querySelectorAll('.gallery-thumb').forEach(function (t) {
                t.classList.toggle('active', t === thumb);
            });
        }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !modal.hidden) {
            closeModal();
            return;
        }
        // '/' focuses search, matching the design directory's shortcut.
        if (ev.key === '/' && modal.hidden && document.activeElement !== searchInput) {
            var tag = (document.activeElement.tagName || '').toLowerCase();
            if (tag !== 'input' && tag !== 'textarea') {
                ev.preventDefault();
                searchInput.focus();
            }
        }
    });

    window.addEventListener('popstate', function () {
        var path = location.pathname.slice(BASE.length) || '/';
        var m    = path.match(/^\/asset\/(.+)$/);

        // Re-derive filter state from the URL so Back through a search history works.
        var params = new URLSearchParams(location.search);
        query.q = params.get('q') || '';
        var cs  = params.get('c');
        query.categories = cs ? cs.split(',').filter(Boolean) : [];
        if (searchInput) {
            searchInput.value = query.q;
            if (searchClear) { searchClear.hidden = query.q === ''; }
        }
        checkboxes.forEach(function (cb) {
            cb.checked = query.categories.indexOf(cb.value) !== -1;
        });

        if (m) {
            loadAsset(decodeURIComponent(m[1]), false);
        } else {
            if (!modal.hidden) { closeModal(false); }
            refresh(false);
        }
    });

    // A deep link (/asset/{slug}) is rendered server-side into AL_STATE, so the modal can open
    // immediately without a second round trip.
    if (state.openAsset) {
        openModal(state.openAsset, false);
    }
}());
