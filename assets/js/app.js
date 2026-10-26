/**
 * BioTrack - shared UI behavior
 *
 * DESKTOP / TABLET (wider than 768px)
 *   - Hamburger collapses the sidebar (width 0) and expands it back.
 *   - The collapsed state is remembered between page loads.
 *
 * MOBILE (768px and narrower)
 *   - The sidebar is hidden by default so the page uses the full width.
 *   - Hamburger slides the sidebar in as a drawer over the page.
 *   - Tapping the dark backdrop, a menu link, pressing Esc, or tapping the
 *     hamburger again tucks it back in.
 *
 * Also closes the admin/employee dropdown when tapping elsewhere.
 */
(function () {
    var STORAGE_KEY = 'biotrack_sidebar_collapsed';
    var mq = window.matchMedia('(max-width: 768px)');

    function isMobile() { return mq.matches; }
    function getApp() { return document.querySelector('.app'); }

    function setDrawer(open) {
        var app = getApp();
        if (!app) return;
        app.classList.toggle('sidebar-open', open);
        document.body.classList.toggle('sidebar-open-lock', open);
    }

    function applyDesktopState() {
        var app = getApp();
        if (!app) return;
        var collapsed = false;
        try { collapsed = localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) { /* ignore */ }
        app.classList.toggle('sidebar-collapsed', collapsed);
    }

    function toggleSidebar() {
        var app = getApp();
        if (!app) return;

        if (isMobile()) {
            setDrawer(!app.classList.contains('sidebar-open'));
            return;
        }

        var collapsed = app.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
    }

    function onBreakpointChange() {
        var app = getApp();
        if (!app) return;
        if (isMobile()) {
            app.classList.remove('sidebar-collapsed'); // desktop state doesn't apply here
            setDrawer(false);
        } else {
            setDrawer(false);
            applyDesktopState();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var app = getApp();

        if (app) {
            // Dark backdrop behind the mobile drawer
            var backdrop = document.createElement('div');
            backdrop.className = 'sidebar-backdrop';
            backdrop.addEventListener('click', function () { setDrawer(false); });
            app.appendChild(backdrop);

            if (isMobile()) {
                app.classList.remove('sidebar-collapsed');
            } else {
                applyDesktopState();
            }

            // Tapping a menu link tucks the drawer in on mobile
            var sidebar = app.querySelector('.sidebar');
            if (sidebar) {
                sidebar.addEventListener('click', function (e) {
                    if (isMobile() && e.target.closest('a')) setDrawer(false);
                });
            }
        }

        var hamburger = document.querySelector('.hamburger');
        if (hamburger) {
            hamburger.addEventListener('click', toggleSidebar);
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setDrawer(false);
        });

        if (mq.addEventListener) mq.addEventListener('change', onBreakpointChange);
        else if (mq.addListener) mq.addListener(onBreakpointChange); // older Safari

        // Account dropdown: opens/closes on click only (no hover).
        // Clicking the chip toggles it; clicking anywhere else or pressing
        // Escape closes it.
        document.addEventListener('click', function (e) {
            var chip = e.target.closest ? e.target.closest('.admin-chip') : null;
            var openChip = document.querySelector('.admin-chip.open');
            if (chip) {
                // ignore clicks on the menu items themselves (they navigate)
                if (e.target.closest('.dropdown')) return;
                chip.classList.toggle('open');
            } else if (openChip) {
                openChip.classList.remove('open');
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var c = document.querySelector('.admin-chip.open');
                if (c) c.classList.remove('open');
            }
        });
    });
})();


/**
 * BioTrack - "Are you sure?" pop-up + bulk (checkbox) selection
 *
 * CONFIRM POP-UP
 *   Add data-confirm="message" to any link or submit button and a pop-up
 *   asks first. Optional attributes:
 *     data-confirm-title="Delete Employee?"
 *     data-confirm-btn="Yes, Delete"
 *     data-confirm-class="btn-danger"   (use btn-primary for non-destructive actions)
 *   In the message, {n} = number of ticked checkboxes, {s} = "s" unless n is 1.
 *
 * BULK SELECT
 *   <form data-bulk> with a .bulk-all checkbox (select all), .bulk-check
 *   checkboxes, and .bulk-btn buttons (disabled until something is ticked)
 *   that may contain a <span class="bulk-count">.
 */
(function () {
    var overlay, box, titleEl, msgEl, okBtn, cancelBtn, onOk = null, lastFocus = null;

    function build() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'confirm-overlay';
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="confirm-box" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmMsg">' +
                '<div class="confirm-icon">&#9888;</div>' +
                '<h3 id="confirmTitle"></h3>' +
                '<p id="confirmMsg"></p>' +
                '<div class="confirm-actions">' +
                    '<button type="button" class="btn confirm-cancel">Cancel</button>' +
                    '<button type="button" class="btn confirm-ok"></button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        box       = overlay.querySelector('.confirm-box');
        titleEl   = overlay.querySelector('h3');
        msgEl     = overlay.querySelector('p');
        okBtn     = overlay.querySelector('.confirm-ok');
        cancelBtn = overlay.querySelector('.confirm-cancel');

        cancelBtn.addEventListener('click', close);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        okBtn.addEventListener('click', function () {
            var fn = onOk;
            close(true);
            if (fn) fn();
        });
        document.addEventListener('keydown', function (e) {
            if (overlay.hidden) return;
            if (e.key === 'Escape') close();
            if (e.key === 'Tab') { // keep focus inside the dialog
                e.preventDefault();
                (document.activeElement === okBtn ? cancelBtn : okBtn).focus();
            }
        });
    }

    function close(keepFocusAway) {
        if (!overlay) return;
        overlay.hidden = true;
        document.body.classList.remove('sidebar-open-lock');
        onOk = null;
        if (keepFocusAway !== true && lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function openConfirm(opts, callback) {
        build();
        lastFocus = document.activeElement;
        titleEl.textContent = opts.title || 'Are you sure?';
        msgEl.textContent   = opts.message || '';
        okBtn.textContent   = opts.button || 'Yes, Delete';
        okBtn.className     = 'btn confirm-ok ' + (opts.btnClass || 'btn-danger');
        box.classList.toggle('is-safe', (opts.btnClass || 'btn-danger') !== 'btn-danger');
        onOk = callback;
        overlay.hidden = false;
        document.body.classList.add('sidebar-open-lock');
        cancelBtn.focus(); // default focus on the safe choice
    }

    function fillMessage(msg, form) {
        var n = form ? form.querySelectorAll('.bulk-check:checked').length : 0;
        return msg.replace(/\{n\}/g, n).replace(/\{s\}/g, n === 1 ? '' : 's');
    }

    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (!el || el.disabled) return;
        var form = el.form || null;

        e.preventDefault();

        // Let the browser complain about empty required fields (e.g. the admin
        // password) before we ask "are you sure?".
        if (form && el.type === 'submit' && !form.noValidate && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        openConfirm({
            title:    el.getAttribute('data-confirm-title') || 'Are you sure?',
            message:  fillMessage(el.getAttribute('data-confirm'), form),
            button:   el.getAttribute('data-confirm-btn') || 'Yes, Delete',
            btnClass: el.getAttribute('data-confirm-class') || 'btn-danger'
        }, function () {
            if (el.tagName === 'A') {
                window.location.href = el.href;
            } else if (form) {
                if (el.name) { // keep the clicked button's name/value (e.g. do=delete)
                    var h = document.createElement('input');
                    h.type = 'hidden'; h.name = el.name; h.value = el.value;
                    form.appendChild(h);
                }
                if (typeof form._btSubmit === 'function') form._btSubmit();   // floating admin-password pop-up
                else form.submit();
            }
        });
    });

    function initBulk(form) {
        var checks = form.querySelectorAll('.bulk-check');
        var all    = form.querySelector('.bulk-all');
        var btns   = form.querySelectorAll('.bulk-btn');
        var counts = form.querySelectorAll('.bulk-count');

        function update() {
            var n = form.querySelectorAll('.bulk-check:checked').length;
            counts.forEach(function (c) { c.textContent = n; });
            btns.forEach(function (b) { b.disabled = (n === 0); });
            if (all) {
                all.checked = checks.length > 0 && n === checks.length;
                all.indeterminate = n > 0 && n < checks.length;
            }
            checks.forEach(function (c) {
                var tr = c.closest('tr');
                if (tr) tr.classList.toggle('row-selected', c.checked);
            });
        }

        form.addEventListener('change', function (e) {
            if (e.target.classList.contains('bulk-all')) {
                checks.forEach(function (c) { c.checked = e.target.checked; });
                update();
            } else if (e.target.classList.contains('bulk-check')) {
                update();
            }
        });
        update();
        window.addEventListener('pageshow', update); // Back button restores ticked boxes
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-bulk]').forEach(initBulk);
    });
})();



/**
 * BioTrack - floating admin-password pop-up
 *
 * Any link / submit button marked  data-admin-confirm  used to open a whole new
 * page (e.g. employee_delete.php?id=5) just to ask for the admin password.
 * Now that page is fetched in the background and its form floats over the
 * current page with a blurred backdrop. Wrong password -> the card shakes and
 * shows the error. Correct password -> the usual redirect + flash message.
 * Without JavaScript (or Ctrl/Cmd+click) the normal full page still works.
 */
(function () {
    var overlay, card, bodyEl, titleEl, iconEl, lastFocus = null, busy = false, closeTimer = null;
    var HEAD = { 'X-BT-Modal': '1', 'X-Requested-With': 'fetch' };

    function build() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'am-overlay';
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="am-card" role="dialog" aria-modal="true" aria-labelledby="amTitle">' +
                '<div class="am-head">' +
                    '<span class="am-icon">&#9888;</span>' +
                    '<h3 id="amTitle"></h3>' +
                    '<button type="button" class="am-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="am-body"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        card    = overlay.querySelector('.am-card');
        bodyEl  = overlay.querySelector('.am-body');
        titleEl = overlay.querySelector('h3');
        iconEl  = overlay.querySelector('.am-icon');

        overlay.querySelector('.am-close').addEventListener('click', close);
        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
        bodyEl.addEventListener('click', function (e) {
            if (e.target.closest('[data-modal-cancel]')) { e.preventDefault(); close(); }
        });
        document.addEventListener('keydown', function (e) {
            if (overlay.hidden || e.key !== 'Escape') return;
            var sure = document.querySelector('.confirm-overlay');   // the "Are you sure?" box handles its own Esc
            if (sure && !sure.hidden) return;
            close();
        });
        window.addEventListener('pageshow', function (e) { if (e.persisted) hideNow(); });
    }

    function show() {
        clearTimeout(closeTimer);
        overlay.classList.remove('is-closing');
        overlay.hidden = false;
        document.body.classList.add('sidebar-open-lock');
    }
    function hideNow() {
        if (!overlay) return;
        clearTimeout(closeTimer);
        overlay.hidden = true;
        overlay.classList.remove('is-closing');
        document.body.classList.remove('sidebar-open-lock');
        busy = false;
    }
    function close() {
        if (!overlay || overlay.hidden) return;
        overlay.classList.add('is-closing');
        closeTimer = setTimeout(function () {
            hideNow();
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }, 180);
    }
    function go(url) { window.location.href = url; }

    function loading() {
        titleEl.textContent = 'Please wait';
        iconEl.innerHTML = '';
        card.classList.add('is-safe');
        bodyEl.innerHTML = '<div class="am-loading"><i></i><span>Loading&hellip;</span></div>';
        show();
    }

    function request(url, init) {
        init = init || {};
        init.credentials = 'same-origin';
        init.cache = 'no-store';
        init.headers = HEAD;
        return fetch(url, init).then(function (res) {
            var to = res.headers.get('X-BT-Redirect');
            if (to) { go(new URL(to, res.url || location.href).href); return null; }   // flash message stays intact
            if (res.redirected) { go(res.url); return null; }                          // safety net
            return res.text().then(function (html) { return { url: res.url, html: html }; });
        });
    }

    function parse(r) {
        var doc = new DOMParser().parseFromString(r.html, 'text/html');
        var panel = doc.querySelector('[data-admin-confirm-panel]');
        return panel ? { doc: doc, panel: panel } : null;
    }

    function render(p, url, shake) {
        var h1  = p.doc.querySelector('.page-head h1');
        var err = p.doc.querySelector('.content .alert-error');
        titleEl.textContent = h1 ? h1.textContent.trim() : 'Confirm';
        bodyEl.innerHTML = (err ? '<div class="alert alert-error am-error"></div>' : '') + p.panel.innerHTML;
        if (err) bodyEl.querySelector('.am-error').textContent = err.textContent.trim();

        var form = bodyEl.querySelector('form');
        var danger = !!(form && form.querySelector('.btn-danger'));
        card.classList.toggle('is-safe', !danger);
        iconEl.innerHTML = danger ? '&#9888;' : '&#10003;';
        if (!form) return;

        var act = form.getAttribute('action');                 // (form.action can be shadowed by an input named "action")
        form.dataset.amUrl = act ? new URL(act, url).href : url;
        form._btSubmit = function () { send(form); };
        form.addEventListener('submit', function (e) { e.preventDefault(); send(form); });

        var pw = form.querySelector('input[type=password]');
        if (pw) setTimeout(function () { pw.focus(); }, 60);
        if (shake) {
            card.classList.remove('shake'); void card.offsetWidth; card.classList.add('shake');
            if (pw) pw.value = '';
        }
    }

    function fail(form, btn, label) {
        busy = false;
        if (btn) { btn.disabled = false; btn.textContent = label; }
        var box = bodyEl.querySelector('.am-error');
        if (!box) {
            box = document.createElement('div');
            box.className = 'alert alert-error am-error';
            bodyEl.insertBefore(box, bodyEl.firstChild);
        }
        box.textContent = 'Something went wrong. Please try again.';
    }

    function send(form) {
        if (busy) return;
        busy = true;
        var btn = form.querySelector('button[type=submit]');
        var label = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Please wait\u2026'; }
        request(form.dataset.amUrl, { method: 'POST', body: new FormData(form) }).then(function (r) {
            if (!r) return;                                    // redirecting
            busy = false;
            var p = parse(r);
            if (p) render(p, r.url, true);                     // wrong password: same card, with the error
            else fail(form, btn, label);
        }).catch(function () { fail(form, btn, label); });
    }

    function open(t) {
        build();
        lastFocus = document.activeElement;
        loading();

        if (t.tagName === 'A') {
            var url = t.href;
            request(url, { method: 'GET' }).then(function (r) {
                if (!r) return;
                var p = parse(r);
                if (p) render(p, r.url, false); else go(url);
            }).catch(function () { go(url); });
            return;
        }

        var form = t.form;
        if (!form) { hideNow(); return; }
        var fd = new FormData(form);
        if (t.name) fd.append(t.name, t.value);                // e.g. action=purge_selected
        var fallback = function () {
            hideNow();
            if (t.name) {
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = t.name; h.value = t.value;
                form.appendChild(h);
            }
            form.submit();
        };
        var formUrl = new URL(form.getAttribute('action') || location.href, location.href).href;
        request(formUrl, { method: 'POST', body: fd }).then(function (r) {
            if (!r) return;
            var p = parse(r);
            if (p) render(p, r.url, false); else fallback();
        }).catch(fallback);
    }

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-admin-confirm]');
        if (!t || t.disabled || e.defaultPrevented) return;
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;   // Ctrl+click still opens a new tab
        e.preventDefault();
        open(t);
    });
})();


/**
 * BioTrack - click-to-sort table headers
 *
 * Every <table class="data-table"> gets sortable headers automatically
 * (click a header to sort A-Z / smallest first, click again to flip). The
 * active header turns yellow and shows an arrow, just like the Employee list.
 *
 * Skipped on purpose:
 *   - tables whose headers already sort on the server (employee list, attendance
 *     list, past employee - they sort across ALL pages, not just the visible one)
 *   - add  data-no-sort  to a <table> to opt out
 *   - header cells with a checkbox / button, and the "#", "Photo" and "Tools" columns
 *   - add  data-sort="value"  to any <td> to sort on that value instead of its text
 *
 * Also used on the Dean's daily report preview (wrapper class "dr-sortable").
 */
(function () {
    var SKIP_HEADERS = ['#', 'photo', 'tools', 'no.', 'row', 'department'];   // everyone is in CBA, so Department never sorts
    var TIME_RE  = /^(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?$/i;
    var BLANK_RE = /^[\s\u2014\u2013\-]*$/;      // empty, "-", em dash

    function cellValue(td) {
        if (!td) return '';
        var v = td.getAttribute('data-sort');
        return (v !== null ? v : td.textContent).replace(/\s+/g, ' ').trim();
    }

    /** Turn text into something comparable: {blank, n (number|null), s (lowercase text)} */
    function parse(text) {
        var out = { blank: BLANK_RE.test(text), n: null, s: text.toLowerCase() };
        if (out.blank) return out;

        var m = TIME_RE.exec(text);                                 // 7:05 AM / 17:30
        if (m) {
            var h = parseInt(m[1], 10) % 12 + (m[3] && m[3].toUpperCase() === 'PM' ? 12 : 0);
            if (!m[3]) h = parseInt(m[1], 10);
            out.n = h * 60 + parseInt(m[2], 10);
            return out;
        }
        if (/^\d{4}-\d{2}-\d{2}/.test(text) ||                       // 2026-10-26
            /^[A-Za-z]{3,9}\.? \d{1,2},? \d{4}/.test(text)) {        // Oct 26, 2026 3:10 PM
            var t = Date.parse(text);
            if (!isNaN(t)) { out.n = t; return out; }
        }
        if (/^[+-]?\d[\d,]*(\.\d+)?%?$/.test(text)) {               // 12 / 1,200 / 85%
            out.n = parseFloat(text.replace(/[,%]/g, ''));
            return out;
        }
        var lead = /^(\d+)\s*(days?|hours?|hrs?|mins?|minutes?)\b/i.exec(text);   // "5 days left"
        if (lead) out.n = parseInt(lead[1], 10);
        return out;
    }

    function compare(a, b) {
        if (a.n !== null && b.n !== null && a.n !== b.n) return a.n - b.n;
        if (a.n !== null && b.n !== null) return 0;
        return a.s.localeCompare(b.s, undefined, { numeric: true, sensitivity: 'base' });
    }

    function isSortableHeader(th) {
        if (th.querySelector('input, button, a')) return false;
        var label = th.textContent.replace(/\s+/g, ' ').trim();
        return label !== '' && SKIP_HEADERS.indexOf(label.toLowerCase()) === -1;
    }

    function setup(table) {
        if (table.getAttribute('data-sort-ready') || table.hasAttribute('data-no-sort')) return;
        if (table.querySelector('thead a.th-sort')) return;          // already sorted by the server
        var thead = table.tHead, tbody = table.tBodies[0];
        if (!thead || !tbody || !thead.rows.length) return;
        table.setAttribute('data-sort-ready', '1');

        var headRow = thead.rows[thead.rows.length - 1];
        var numCol = -1;                                             // the "#" column gets renumbered after a sort
        Array.prototype.forEach.call(headRow.cells, function (th, i) {
            if (th.textContent.replace(/\s+/g, ' ').trim() === '#') numCol = i;
        });

        var current = { col: -1, dir: 'asc' };

        function sortBy(col, th) {
            var rows = Array.prototype.filter.call(tbody.rows, function (r) { return r.cells.length > col && r.cells[0].colSpan < 2; });
            if (rows.length < 2) return;

            current.dir = (current.col === col && current.dir === 'asc') ? 'desc' : 'asc';
            current.col = col;
            var mult = current.dir === 'asc' ? 1 : -1;

            var keyed = rows.map(function (r, i) { return { r: r, i: i, v: parse(cellValue(r.cells[col])) }; });
            keyed.sort(function (x, y) {
                if (x.v.blank !== y.v.blank) return x.v.blank ? 1 : -1;      // blanks always last
                if (x.v.blank) return x.i - y.i;
                return (compare(x.v, y.v) * mult) || (x.i - y.i);            // ties keep their old order
            });
            keyed.forEach(function (k, n) {
                tbody.appendChild(k.r);
                if (numCol > -1 && k.r.cells[numCol]) k.r.cells[numCol].textContent = n + 1;
            });

            Array.prototype.forEach.call(headRow.cells, function (h) {
                h.classList.remove('th-sorted');
                h.removeAttribute('aria-sort');
                var old = h.querySelector('.sort-arrow');
                if (old) old.remove();
            });
            th.classList.add('th-sorted');
            th.setAttribute('aria-sort', current.dir === 'asc' ? 'ascending' : 'descending');
            var arrow = document.createElement('span');
            arrow.className = 'sort-arrow';
            arrow.innerHTML = current.dir === 'asc' ? ' &#9650;' : ' &#9660;';
            th.appendChild(arrow);
        }

        Array.prototype.forEach.call(headRow.cells, function (th, col) {
            if (!isSortableHeader(th)) return;
            th.classList.add('th-sortable');
            th.setAttribute('tabindex', '0');
            th.setAttribute('title', 'Sort by ' + th.textContent.replace(/\s+/g, ' ').trim());
            th.addEventListener('click', function () { sortBy(col, th); });
            th.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sortBy(col, th); }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table.data-table, .dr-sortable table').forEach(setup);
    });
})();
