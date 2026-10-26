/**
 * BioTrack - fingerprint pop-up (Add / Edit Employee)
 *
 * An employee can have several fingers. This file:
 *   - ADDS a finger   : scans it on the Arduino sensor, then lists it in the page
 *   - REMOVES a finger: deletes it from the Arduino sensor, then from the website
 *
 * Talks to the Arduino running biotrack_enroll.ino over USB using the
 * browser's Web Serial API. Needs Google Chrome or Microsoft Edge, and the
 * page must be opened from http://localhost (or https://).
 *
 * Page requirements (provided by includes/fingerprint_panel.php + fingerprint_enroll.php):
 *   #fp-panel / #fp-list / #fp-open   the finger list and the "Add" button
 *   #fp-overlay                       the pop-up
 */
(function () {
    'use strict';

    // Fingerprint icon (inline SVG, replaces the old hand emoji)
    var FP_ICON = '<svg class="ico-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/></svg>';
    var FP_BTN  = '<svg class="ico-svg btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/></svg>';

    var cfg  = window.BIOTRACK_FP || {};
    var used = cfg.used || {};                      // { "scannerId": "Employee name" } saved in the database
    var MAX  = cfg.max || 10;                       // fingers allowed per employee
    var REMOVE_URL = cfg.removeUrl || 'fingerprint_remove.php';
    var BAUD = 9600;

    function $(id) { return document.getElementById(id); }

    var openBtn = $('fp-open');
    var overlay = $('fp-overlay');
    var panel   = $('fp-panel');
    var list    = $('fp-list');
    if (!openBtn || !overlay || !panel || !list) return;

    var employeePk = parseInt(panel.getAttribute('data-employee'), 10) || 0;   // 0 on the Add page

    var el = {
        orb: $('fp-orb'), icon: $('fp-icon'), msg: $('fp-msg'), sub: $('fp-sub'),
        steps: $('fp-steps'), action: $('fp-action'), cancel: $('fp-cancel'),
        change: $('fp-change'), skip: $('fp-skip'), conn: $('fp-conn'), connText: $('fp-conn-text'),
        mem: $('fp-mem'), countText: $('fp-count-text'), hint: $('fp-hint')
    };

    var enc = new TextEncoder();
    var dec = new TextDecoder();

    var conn       = null;   // { port, reader, writer, ready, capacity }
    var handler    = null;   // receives each line the Arduino sends
    var actionFn   = null;
    var task       = null;   // what to do once the scanner is ready: function (session)
    var pendingRemove = null; // scanner ID being removed (null = we are adding)
    var watchdog   = null;
    var closeTimer = null;
    var session    = 0;      // changes whenever the pop-up opens/closes, so old async work goes quiet
    var enrolling  = false;

    // ============================================================ finger list on the page

    function items() { return list.querySelectorAll('li'); }

    function findItem(uid) {
        var lis = items();
        for (var i = 0; i < lis.length; i++) {
            if (lis[i].getAttribute('data-uid') === String(uid)) return lis[i];
        }
        return null;
    }

    function panelHas(uid) { return !!findItem(uid); }

    function isTaken(n) { return !!used[String(n)] || panelHas(n); }

    // Renumbers "Finger 1, 2, 3...", updates the count text and the Add button.
    function refreshPanel() {
        var lis = items(), n = lis.length;
        for (var i = 0; i < n; i++) lis[i].querySelector('.fp-n').textContent = 'Finger ' + (i + 1);
        el.countText.textContent = n === 0 ? 'No fingerprint enrolled yet.'
                                           : n + ' fingerprint' + (n === 1 ? '' : 's') + ' enrolled';
        panel.classList.toggle('enrolled', n > 0);
        openBtn.innerHTML = FP_BTN + (n === 0 ? ' Add Fingerprint' : ' Add Another Finger');
        openBtn.disabled = n >= MAX;
        el.hint.textContent = n >= MAX
            ? 'Maximum of ' + MAX + ' fingers reached. Remove one to add another.'
            : 'You can add up to ' + MAX + ' fingers per employee (' + (MAX - n) + ' more). New fingers are kept when you save the employee.';
    }

    function addItem(uid, saved) {
        var li = document.createElement('li');
        li.setAttribute('data-uid', uid);
        li.setAttribute('data-saved', saved ? '1' : '0');

        var name = document.createElement('span'); name.className = 'fp-n';
        var sid  = document.createElement('span'); sid.className = 'fp-sid'; sid.textContent = 'Scanner ID ' + uid;
        li.appendChild(name);
        li.appendChild(sid);
        if (!saved) {
            var tag = document.createElement('span');
            tag.className = 'pill pill-gray fp-unsaved';
            tag.textContent = 'Not saved yet';
            li.appendChild(tag);
        }
        var btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'btn btn-danger fp-remove'; btn.textContent = 'Remove';
        li.appendChild(btn);
        var hid = document.createElement('input');
        hid.type = 'hidden'; hid.name = 'fingerprint_uids[]'; hid.value = uid;
        li.appendChild(hid);

        list.appendChild(li);
        refreshPanel();
    }

    function dropItem(uid) {
        var li = findItem(uid);
        if (li) li.parentNode.removeChild(li);
        delete used[String(uid)];
        refreshPanel();
    }

    // ============================================================ pop-up UI helpers

    function setConn(text, on) {
        el.conn.className = 'fp-conn' + (on ? ' on' : '');
        el.connText.textContent = text;
    }

    // "Scanner memory: 3 of 200 used, 197 free"
    function showMem(c) {
        if (!c || !c.capacity || c.used === null) { el.mem.hidden = true; return; }
        el.mem.hidden = false;
        el.mem.textContent = 'Scanner memory: ' + c.used + ' of ' + c.capacity + ' used, ' +
                             Math.max(0, c.capacity - c.used) + ' free';
    }

    function markStep(n) {
        var lis = el.steps.querySelectorAll('li');
        for (var i = 0; i < lis.length; i++) {
            var s = parseInt(lis[i].getAttribute('data-step'), 10);
            lis[i].className = s < n ? 'done' : (s === n ? 'active' : '');
        }
    }

    function show(o) {
        el.orb.className = 'fp-orb ' + (o.state || '');
        el.icon.innerHTML = o.icon || FP_ICON;
        el.msg.textContent = o.msg || '';
        el.sub.textContent = o.sub || '';
        el.steps.hidden = !o.steps;
        if (o.steps) markStep(o.step || 1);
        if (o.action) {
            el.action.hidden = false;
            el.action.textContent = o.action.label;
            actionFn = o.action.fn;
        } else {
            el.action.hidden = true;
            actionFn = null;
        }
        el.change.hidden = !o.change;
        el.skip.hidden = !(o.skip && pendingRemove !== null);
        el.cancel.hidden = !!o.noCancel;
        el.cancel.textContent = o.cancelLabel || 'Cancel';
    }

    // ============================================================ serial link

    function send(cmd) {
        if (!conn) return Promise.reject(new Error('not connected'));
        return conn.writer.write(enc.encode(cmd + '\n'));
    }

    async function pump(c) {
        var buf = '';
        try {
            for (;;) {
                var r = await c.reader.read();
                if (r.done) break;
                buf += dec.decode(r.value, { stream: true });
                var parts = buf.split('\n');
                buf = parts.pop();
                for (var i = 0; i < parts.length; i++) {
                    var line = parts[i].trim();
                    if (!line) continue;

                    // The Arduino reports its memory after boot / enroll / delete: "OK USED 3 200"
                    var um = /^OK USED (\d+) (\d+)/.exec(line);
                    if (um) { c.used = parseInt(um[1], 10); c.capacity = parseInt(um[2], 10); showMem(c); continue; }

                    if (!handler) continue;
                    try { handler(line); } catch (e) { if (window.console) console.error(e); }
                }
            }
        } catch (e) { /* cable pulled */ }
        if (conn === c) lost();
    }

    async function connect(port) {
        try {
            await port.open({ baudRate: BAUD });
        } catch (e) {
            throw 'open';
        }
        var c = { port: port, reader: port.readable.getReader(), writer: port.writable.getWriter(), ready: false, capacity: 0, used: null };
        conn = c;
        pump(c);
    }

    async function dropConn() {
        var c = conn;
        conn = null;
        if (!c) return;
        try { await c.reader.cancel(); } catch (e) {}
        try { c.reader.releaseLock(); } catch (e) {}
        try { c.writer.releaseLock(); } catch (e) {}
        try { await c.port.close(); } catch (e) {}
        setConn('Not connected', false);
        showMem(null);
    }

    function lost() {
        var c = conn;
        conn = null;
        disarm();
        handler = null;
        enrolling = false;
        if (c) {
            try { c.reader.releaseLock(); } catch (e) {}
            try { c.writer.releaseLock(); } catch (e) {}
            try { c.port.close(); } catch (e) {}
        }
        setConn('Not connected', false);
        showMem(null);
        if (!overlay.hidden) {
            show({
                state: 'err', icon: '&#9888;', msg: 'The scanner was disconnected.',
                sub: 'Check the USB cable, then try again.',
                action: { label: 'Try Again', fn: retry }, change: true, skip: true, cancelLabel: 'Close'
            });
        }
    }

    // Waits for the Arduino to say it is ready (resolves) or reports a problem (rejects with a code).
    function handshake(fresh) {
        return new Promise(function (resolve, reject) {
            var c = conn, mine = session, done = false, timers = [], h;
            function end(ok, v) {
                if (done) return;
                done = true;
                timers.forEach(clearTimeout);
                if (handler === h) handler = null;        // never wipe a newer listener
                if (ok) resolve(v); else reject(v);
            }
            h = function (line) {
                var m = /^OK READY (\d+)/.exec(line);
                if (m) { c.ready = true; c.capacity = parseInt(m[1], 10); end(true); }
                else if (line === 'ERR NOSENSOR') end(false, 'nosensor');
            };
            handler = h;
            function ping() {
                if (done) return;
                if (mine !== session) return end(false, 'stale');   // pop-up was closed/reopened
                send('PING').catch(function () { end(false, 'lost'); });
            }
            // A freshly opened port makes the Arduino restart (~2s) and it announces itself,
            // so the first PING is only a fallback.
            var first = fresh ? 3000 : 0;
            timers.push(setTimeout(ping, first));
            timers.push(setTimeout(ping, first + 9000));
            timers.push(setTimeout(function () { end(false, mine !== session ? 'stale' : 'timeout'); }, first + 20000));
        });
    }

    // ============================================================ ADD a finger

    // First free scanner slot: not used by anyone in the database, not already on this form.
    function pickId() {
        var cap = conn ? conn.capacity : 0;
        for (var i = 1; i <= cap; i++) {
            if (!isTaken(i)) return i;
        }
        return 0;
    }

    function arm() {
        disarm();
        watchdog = setTimeout(function () {
            if (enrolling) { send('CANCEL').catch(function () {}); enrollError('TIMEOUT'); }
        }, 40000);
    }
    function disarm() { clearTimeout(watchdog); watchdog = null; }

    function startEnroll(my, staleRetried) {
        if (my !== session) return;
        if (items().length >= MAX) {
            show({ state: 'err', icon: '&#9888;', msg: 'This employee already has ' + MAX + ' fingers.',
                   sub: 'Remove one before adding another.', cancelLabel: 'Close' });
            return;
        }
        var id = pickId();
        if (!id) {
            show({ state: 'err', icon: '&#9888;', msg: 'The scanner memory is full.',
                   sub: 'Remove fingerprints that are no longer needed before adding more.', cancelLabel: 'Close' });
            return;
        }

        enrolling = true;
        show({ state: 'wait', msg: 'Place your finger on the scanner',
               sub: 'This will be Finger ' + (items().length + 1) + '. Use a finger you have not added yet.', steps: true, step: 1 });

        handler = function (line) {
            if (my !== session || !enrolling) return;
            var m;
            if (line === 'STATUS PLACE') {
                arm();
            } else if (line === 'STATUS CAPTURED1' || line === 'STATUS CAPTURED2') {
                arm();
            } else if (line === 'STATUS REMOVE') {
                arm();
                show({ state: 'wait', icon: '&#9757;', msg: 'Good! Now lift your finger', sub: '', steps: true, step: 2 });
            } else if (line === 'STATUS PLACE_AGAIN') {
                arm();
                show({ state: 'wait', msg: 'Place the same finger again', sub: 'Keep it steady until the scan is done.', steps: true, step: 3 });
            } else if (line === 'STATUS PROCESSING') {
                arm();
                show({ state: 'busy', icon: '&#8987;', msg: 'Saving fingerprint...', sub: '', steps: true, step: 4 });
            } else if ((m = /^OK ENROLLED (\d+)/.exec(line))) {
                success(m[1]);
            } else if ((m = /^ERR DUPLICATE (\d+)/.exec(line))) {
                duplicate(m[1], my, staleRetried);
            } else if (line.indexOf('ERR ') === 0) {
                enrollError(line.slice(4).split(' ')[0]);
            }
        };

        arm();
        send('ENROLL ' + id).catch(function () { enrollError('LOST'); });
    }

    function duplicate(slot, my, staleRetried) {
        disarm();
        if (panelHas(slot)) {
            enrolling = false;
            show({ state: 'err', icon: '&#9888;', msg: 'You already added this finger.',
                   sub: 'It is already on this employee\'s list. Use a different finger.',
                   action: { label: 'Try Again', fn: retryEnroll }, cancelLabel: 'Close' });
            return;
        }
        if (used[slot]) {
            enrolling = false;
            show({ state: 'err', icon: '&#9888;', msg: 'This finger is already registered.',
                   sub: 'It belongs to ' + used[slot] + '. Use a different finger.',
                   action: { label: 'Try Again', fn: retryEnroll }, cancelLabel: 'Close' });
            return;
        }
        // The matching slot isn't linked to any employee (left over from a deleted record) - clear it and go again.
        if (staleRetried) {
            enrolling = false;
            show({ state: 'err', icon: '&#9888;', msg: 'This finger is already stored on the scanner.',
                   sub: 'Slot ' + slot + ' could not be cleared. Try a different finger.',
                   action: { label: 'Try Again', fn: retryEnroll }, cancelLabel: 'Close' });
            return;
        }
        show({ state: 'busy', icon: '&#8987;', msg: 'Cleaning up an old fingerprint...', sub: '' });
        handler = function (line) {
            if (my !== session) return;
            if (/^OK DELETED/.test(line)) { handler = null; startEnroll(my, true); }
            else if (/^ERR DELETE/.test(line)) { handler = null; enrollError('STORE'); }
        };
        send('DELETE ' + slot).catch(function () { enrollError('LOST'); });
    }

    function success(uid) {
        enrolling = false;
        disarm();
        handler = null;
        addItem(uid, false);
        var n = items().length;
        var more = n < MAX;
        show({ state: 'ok', icon: '&#10004;', msg: 'Finger ' + n + ' recorded!',
               sub: 'Scanner ID ' + uid + '. ' + (more ? 'Add another finger, or press Done. ' : '') + 'Remember to save the employee.',
               steps: true, step: 5,
               action: more ? { label: 'Add Another Finger', fn: retryEnroll } : { label: 'Done', fn: closeDialog },
               noCancel: !more, cancelLabel: 'Done' });
    }

    var ERRORS = {
        TIMEOUT:  ['No finger was detected.', 'Please try again and press your finger firmly on the sensor.'],
        BADIMAGE: ['The scan was not clear.', 'Clean the sensor and your finger, then try again.'],
        MISMATCH: ['The two scans did not match.', 'Use the same finger both times and keep it steady.'],
        STORE:    ['The scanner could not save the fingerprint.', 'Please try again.'],
        SENSOR:   ['Lost contact with the fingerprint sensor.', 'Check the sensor wiring, then try again.'],
        NOSENSOR: ['The fingerprint sensor is not responding.', 'Check the sensor wiring, then try again.'],
        BADID:    ['The scanner rejected that ID.', 'Please try again.'],
        LOST:     ['The scanner was disconnected.', 'Check the USB cable, then try again.']
    };

    function enrollError(code) {
        var wasEnrolling = enrolling;
        enrolling = false;
        disarm();
        handler = null;
        if (code === 'CANCELLED') return;               // user pressed Cancel; nothing to show
        if (!wasEnrolling && code !== 'LOST') return;
        if (code === 'SENSOR' || code === 'NOSENSOR') { if (conn) conn.ready = false; }
        var e = ERRORS[code] || ['Something went wrong.', 'Please try again.'];
        show({ state: 'err', icon: '&#9888;', msg: e[0], sub: e[1],
               action: { label: 'Try Again', fn: (code === 'LOST' ? retry : (conn && conn.ready ? retryEnroll : retry)) },
               change: code === 'LOST' || code === 'NOSENSOR', cancelLabel: 'Close' });
    }

    // ============================================================ REMOVE a finger

    function askRemove(uid) {
        clearTimeout(closeTimer);
        session++;
        enrolling = false;
        disarm();
        handler = null;
        pendingRemove = uid;
        overlay.hidden = false;

        var li = findItem(uid);
        var n = 0, lis = items();
        for (var i = 0; i < lis.length; i++) if (lis[i] === li) n = i + 1;

        show({ state: 'err', icon: '&#9888;', msg: 'Remove Finger ' + n + '?',
               sub: 'It will be deleted from the scanner (ID ' + uid + ') and from this employee.',
               action: { label: 'Yes, Remove', fn: function () {
                   task = function (my) { runRemove(my, uid); };
                   start(++session);
               } },
               cancelLabel: 'Cancel' });
    }

    // Step 1: delete it from the Arduino sensor.
    function runRemove(my, uid) {
        if (my !== session) return;
        show({ state: 'busy', icon: '&#8987;', msg: 'Removing fingerprint...', sub: 'Deleting it from the scanner.', noCancel: true });

        var timer = setTimeout(function () {
            if (my !== session) return;
            handler = null;
            removeError('The scanner did not answer.', 'Check the USB cable and try again.', uid);
        }, 10000);

        handler = function (line) {
            if (my !== session) return;
            var m = /^OK DELETED (\d+)/.exec(line);
            if (m && m[1] === String(uid)) {
                clearTimeout(timer);
                handler = null;
                removeFromWebsite(my, uid);
            } else if (/^ERR DELETE/.test(line)) {
                clearTimeout(timer);
                handler = null;
                removeError('The scanner could not delete that fingerprint.', 'Please try again.', uid);
            }
        };
        send('DELETE ' + uid).catch(function () {
            clearTimeout(timer);
            removeError('The scanner was disconnected.', 'Check the USB cable, then try again.', uid);
        });
    }

    // Step 2: forget it on the website (only needed if it was already saved for this employee).
    function removeFromWebsite(my, uid) {
        if (my !== session) return;
        var li = findItem(uid);
        var saved = li && li.getAttribute('data-saved') === '1';
        if (!saved || !employeePk) { removed(my, uid); return; }

        show({ state: 'busy', icon: '&#8987;', msg: 'Removing fingerprint...', sub: 'Updating the employee record.', noCancel: true });
        var body = new URLSearchParams();
        body.set('employee', employeePk);
        body.set('scanner_id', uid);
        fetch(REMOVE_URL, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.ok) throw new Error((j && j.error) || 'failed');
                removed(my, uid);
            })
            .catch(function () {
                if (my !== session) return;
                show({ state: 'err', icon: '&#9888;', msg: 'Removed from the scanner, but the website was not updated.',
                       sub: 'Check your connection or sign in again, then press Try Again.',
                       action: { label: 'Try Again', fn: function () { removeFromWebsite(session, uid); } },
                       cancelLabel: 'Close' });
            });
    }

    function removed(my, uid) {
        if (my !== session) return;
        dropItem(uid);
        pendingRemove = null;
        task = null;
        show({ state: 'ok', icon: '&#10004;', msg: 'Fingerprint removed.',
               sub: 'It was deleted from the scanner and from this employee.',
               action: { label: 'Done', fn: closeDialog }, noCancel: true });
        closeTimer = setTimeout(function () { if (my === session) closeDialog(); }, 1800);
    }

    function removeError(msg, sub, uid) {
        show({ state: 'err', icon: '&#9888;', msg: msg, sub: sub,
               action: { label: 'Try Again', fn: function () { start(++session); } },
               change: true, skip: true, cancelLabel: 'Close' });
    }

    // "Remove from the website only" - used when the scanner is not available.
    function skipScanner() {
        if (pendingRemove === null) return;
        var uid = pendingRemove;
        removeFromWebsite(++session, uid);
    }

    // ============================================================ pop-up flow

    function connectError(code) {
        var msgs = {
            nosensor: ['The Arduino is connected, but the fingerprint sensor did not answer.',
                       'Check the sensor wiring (sensor TX to D2, sensor RX to D3, power and GND), then try again.'],
            timeout:  ['The Arduino did not answer.',
                       'Make sure the BioTrack sketch is uploaded to it and that the Arduino Serial Monitor is closed.'],
            open:     ['Could not open the USB port.',
                       'Close the Arduino Serial Monitor (or any other tab using the scanner) and try again.'],
            lost:     ['The scanner was disconnected.', 'Check the USB cable, then try again.']
        };
        var m = msgs[code] || msgs.timeout;
        if (code === 'open' || code === 'lost') dropConn();
        setConn(code === 'nosensor' ? 'Arduino connected' : 'Not connected', code === 'nosensor');
        show({ state: 'err', icon: '&#9888;', msg: m[0], sub: m[1],
               action: { label: 'Try Again', fn: retry }, change: true, skip: true, cancelLabel: 'Close' });
    }

    // Connects/handshakes with the Arduino, then runs whatever task was asked for (add or remove).
    async function handshakeThenTask(my, fresh) {
        show({ state: 'busy', icon: '&#8987;', msg: 'Waking up the scanner...',
               sub: fresh ? 'The Arduino restarts when connected. This takes a few seconds.' : '' });
        setConn('Connecting...', false);
        if (!conn.ready) {
            try { await handshake(fresh); }
            catch (code) { if (my === session) connectError(code); return; }
        }
        if (my !== session) return;
        setConn('Scanner ready', true);
        showMem(conn);
        if (task) task(my);
    }

    function askForPort() {
        setConn('Not connected', false);
        show({ state: 'idle', msg: 'Connect the fingerprint scanner',
               sub: 'Plug the Arduino into this computer with the USB cable, press Connect Scanner, then choose its port (usually "Arduino Uno" or "USB-SERIAL CH340").',
               action: { label: 'Connect Scanner', fn: pickPort }, skip: true, cancelLabel: 'Cancel' });
    }

    async function pickPort() {
        var port;
        try { port = await navigator.serial.requestPort(); }
        catch (e) { return; }                            // chooser dismissed
        var my = ++session;
        if (conn) await dropConn();
        show({ state: 'busy', icon: '&#8987;', msg: 'Connecting to the scanner...', sub: '' });
        try {
            await connect(port);
            await handshakeThenTask(my, true);
        } catch (code) { if (my === session) connectError(code); }
    }

    async function start(my) {
        if (!('serial' in navigator)) {
            show({ state: 'err', icon: '&#9888;', msg: 'This browser cannot talk to the scanner.',
                   sub: 'Open this page in Google Chrome or Microsoft Edge, using http://localhost (or https://).',
                   skip: true, cancelLabel: 'Close' });
            return;
        }
        try {
            var fresh = false;
            if (!conn) {
                var ports = await navigator.serial.getPorts();     // ports allowed earlier
                if (my !== session) return;
                if (!ports.length) { askForPort(); return; }
                show({ state: 'busy', icon: '&#8987;', msg: 'Connecting to the scanner...', sub: '' });
                await connect(ports[0]);
                fresh = true;
            }
            await handshakeThenTask(my, fresh);
        } catch (code) { if (my === session) connectError(code); }
    }

    function retry()       { start(++session); }
    function retryEnroll() { var my = ++session; startEnroll(my, false); }

    function openDialog() {
        clearTimeout(closeTimer);
        pendingRemove = null;
        task = function (my) { startEnroll(my, false); };
        overlay.hidden = false;
        start(++session);
    }

    function closeDialog() {
        clearTimeout(closeTimer);
        session++;
        if (enrolling) send('CANCEL').catch(function () {});
        enrolling = false;
        pendingRemove = null;
        task = null;
        disarm();
        handler = null;
        overlay.hidden = true;
        openBtn.focus();
    }

    // ============================================================ wiring

    refreshPanel();
    openBtn.addEventListener('click', openDialog);
    el.cancel.addEventListener('click', closeDialog);
    el.action.addEventListener('click', function () { if (actionFn) actionFn(); });
    el.change.addEventListener('click', pickPort);
    el.skip.addEventListener('click', skipScanner);
    list.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.fp-remove') : null;
        if (!b) return;
        var li = b.closest('li');
        if (li) askRemove(li.getAttribute('data-uid'));
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hidden) closeDialog();
    });
    if ('serial' in navigator) {
        navigator.serial.addEventListener('disconnect', function (e) {
            if (conn && conn.port === e.target) lost();
        });
    }
})();
