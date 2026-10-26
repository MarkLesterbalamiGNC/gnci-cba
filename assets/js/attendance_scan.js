/**
 * BioTrack - Scan Attendance (attendance_scan.php)
 *
 * Keeps asking the Arduino (biotrack_enroll.ino, SCAN command) "who is touching the sensor?".
 * When it answers "OK MATCH <slot>", the slot number is sent to attendance_punch.php,
 * which finds the employee and records Time In / Time Out.
 * Uses the browser's Web Serial API (Chrome / Edge, http://localhost or https).
 */
(function () {
    'use strict';

    // Fingerprint icon (inline SVG, replaces the old hand emoji)
    var FP_ICON = '<svg class="ico-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/></svg>';

    var BAUD = 9600;
    function $(id) { return document.getElementById(id); }
    var el = {
        conn: $('scan-conn'), connText: $('scan-conn-text'), clock: $('scan-clock'), date: $('scan-date'),
        orb: $('scan-orb'), icon: $('scan-icon'), msg: $('scan-msg'), sub: $('scan-sub'),
        photoSlot: $('scan-photo-slot'), connect: $('scan-connect'), stop: $('scan-stop'), log: $('scan-log'), popout: $('scan-popout')
    };
    if (!el.connect) return;

    var enc = new TextEncoder(), dec = new TextDecoder();
    var conn = null, running = false, waiting = null, resetTimer = null;

    // ---------- clock
    function tick() {
        var d = new Date();
        el.clock.textContent = d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        el.date.textContent = d.toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }
    tick(); setInterval(tick, 1000);

    // ---------- display
    function setConn(text, on) { el.connText.textContent = text; el.conn.classList.toggle('on', !!on); }
    function show(state, icon, msg, sub, photo) {
        clearTimeout(resetTimer);
        el.orb.className = 'scan-orb' + (state ? ' ' + state : '');
        el.icon.innerHTML = icon;
        el.msg.textContent = msg;
        el.sub.textContent = sub || '';
        el.photoSlot.innerHTML = '';
        if (photo) {
            var img = document.createElement('img');
            img.className = 'scan-photo'; img.src = 'uploads/photos/' + encodeURIComponent(photo); img.alt = '';
            el.photoSlot.appendChild(img);
        }
    }
    function ready() { show('', FP_ICON, 'Place your finger on the scanner', 'Ready.'); }
    function readySoon(ms) { clearTimeout(resetTimer); resetTimer = setTimeout(function () { if (running) ready(); }, ms); }
    function addLog(text) {
        var li = document.createElement('li');
        li.innerHTML = '<span></span><span style="color:#8a94a6"></span>';
        li.children[0].textContent = text;
        li.children[1].textContent = new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
        el.log.insertBefore(li, el.log.firstChild);
        while (el.log.children.length > 8) el.log.removeChild(el.log.lastChild);
    }

    // ---------- serial
    function send(cmd) { return conn.writer.write(enc.encode(cmd + '\n')); }

    function waitFor(test, ms) {            // resolves with the first line test() accepts
        return new Promise(function (resolve, reject) {
            var t = setTimeout(function () { waiting = null; reject('timeout'); }, ms);
            waiting = function (line) {
                var r = test(line);
                if (r !== undefined) { clearTimeout(t); waiting = null; resolve(r); }
            };
        });
    }

    async function pump(c) {
        var buf = '';
        try {
            for (;;) {
                var r = await c.reader.read();
                if (r.done) break;
                buf += dec.decode(r.value, { stream: true });
                var parts = buf.split('\n'); buf = parts.pop();
                parts.forEach(function (p) { var line = p.trim(); if (line && waiting) waiting(line); });
            }
        } catch (e) { /* cable pulled */ }
        if (conn === c) lost();
    }

    function lost() {
        running = false; conn = null; waiting = null;
        setConn('Scanner not connected', false);
        show('err', '&#9888;', 'The scanner was disconnected.', 'Check the USB cable, then press Connect Scanner.');
        el.connect.hidden = false; el.stop.hidden = true;
    }

    async function stop() {
        running = false;
        var c = conn; conn = null; waiting = null;
        if (c) {
            // Tell the Arduino to stop waiting for a finger BEFORE the port is closed,
            // otherwise its LCD keeps saying "Place finger" until its own timeout.
            try {
                await Promise.race([c.writer.write(enc.encode('CANCEL\n')), sleep(500)]);
                await sleep(250);      // give the Arduino a moment to receive it
            } catch (e) {}
            try { await c.reader.cancel(); } catch (e) {}
            try { c.reader.releaseLock(); } catch (e) {}
            try { c.writer.releaseLock(); } catch (e) {}
            try { await c.port.close(); } catch (e) {}
        }
        setConn('Scanner not connected', false);
        try { sessionStorage.setItem('scan_stopped', '1'); } catch (e) {}
        show('', FP_ICON, 'Scanning stopped.', 'Press Connect Scanner to start again.');
        el.connect.hidden = false; el.stop.hidden = true;
    }

    async function start(auto) {
        if (!('serial' in navigator)) {
            show('err', '&#9888;', 'This browser cannot talk to the scanner.', 'Open this page in Google Chrome or Microsoft Edge.');
            return;
        }
        if (conn) return;
        var port;
        try {
            var known = await navigator.serial.getPorts();
            if (auto === true && !known.length) return;      // nothing allowed yet: wait for the button
            port = known.length ? known[0] : await navigator.serial.requestPort();
        } catch (e) { return; }                       // person closed the chooser
        try { sessionStorage.removeItem('scan_stopped'); } catch (e) {}

        show('', '&#8987;', 'Connecting...', 'The Arduino restarts when connected - this takes a few seconds.');
        try { await port.open({ baudRate: BAUD }); }
        catch (e) {
            show('err', '&#9888;', 'Could not open the scanner.', 'Close the Arduino Serial Monitor and any other BioTrack tab using it, then try again.');
            return;
        }
        var c = { port: port, reader: port.readable.getReader(), writer: port.writable.getWriter() };
        conn = c; pump(c);

        // Wait for the Arduino to report ready (it restarts and announces itself; PING is the fallback)
        try {
            var p = waitFor(function (l) { if (/^OK READY/.test(l)) return true; if (l === 'ERR NOSENSOR') return false; }, 15000);
            setTimeout(function () { if (conn === c) send('PING').catch(function () {}); }, 3000);
            var ok = await p;
            if (!ok) throw 'nosensor';
        } catch (e) {
            await stop();
            show('err', '&#9888;', e === 'nosensor' ? 'The fingerprint sensor was not found.' : 'The scanner did not answer.',
                 'Check the wiring and that biotrack_enroll.ino (with the SCAN command) is uploaded.');
            return;
        }

        setConn('Scanner connected', true);
        el.connect.hidden = true; el.stop.hidden = false;
        running = true; ready();
        loop(c);
    }

    // ---------- scan loop
    async function loop(c) {
        while (running && conn === c) {
            var line;
            try {
                await send('SCAN');
                line = await waitFor(function (l) {
                    if (/^OK MATCH /.test(l) || /^ERR /.test(l)) return l;
                }, 45000);
            } catch (e) {
                if (!running || conn !== c) return;
                send('CANCEL').catch(function () {});
                await sleep(500);
                continue;
            }

            var m = /^OK MATCH (\d+)/.exec(line);
            if (m) await punch(parseInt(m[1], 10));
            else if (line === 'ERR NOMATCH') { show('err', '&#10060;', 'Fingerprint not recognised.', 'Try again, or ask an admin to enroll your finger.'); readySoon(3000); }
            else if (line === 'ERR BADIMAGE' || line === 'ERR MISMATCH') { show('err', '&#10060;', 'Could not read the finger.', 'Press a little firmer and try again.'); readySoon(2500); }
            else if (line === 'ERR SENSOR' || line === 'ERR NOSENSOR') { show('err', '&#9888;', 'The sensor is not responding.', 'Check the wiring.'); readySoon(3000); }
            // ERR TIMEOUT = nobody touched it: just ask again

            // The Arduino prints STATUS LIFTED once the finger is off the sensor (no reply for timeouts)
            if (!/^ERR (TIMEOUT|CANCELLED)/.test(line)) {
                try { await waitFor(function (l) { if (l === 'STATUS LIFTED') return true; }, 15000); } catch (e) {}
            }
        }
    }

    function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    async function punch(slot) {
        show('', '&#8987;', 'Checking...', '');
        var data;
        try {
            var res = await fetch('attendance_punch.php', {
                method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' },
                body: new URLSearchParams({ scanner_id: slot })
            });
            data = await res.json();
        } catch (e) {
            show('err', '&#9888;', 'Could not reach the website.', 'Your login may have expired - reload this page.');
            readySoon(4000); return;
        }
        if (!data.ok) { show('err', '&#10060;', data.error || 'Could not record attendance.', ''); readySoon(4000); return; }

        if (data.result === 'duplicate') {
            show('ok', '&#10004;', data.name, 'Already recorded at ' + data.time + '.', data.photo);
        } else if (data.result === 'ignored') {
            show('ok', '&#10004;', data.name, 'Scan received at ' + data.time + ' (nothing new to record).', data.photo);
        } else {
            var what = data.result === 'in' ? 'Time In' : 'Time Out';
            var late = data.result === 'in' && data.status === 'Late';
            show(late ? 'late' : 'ok', late ? '&#9203;' : '&#10004;', data.name,
                 what + ' at ' + data.time + (data.shift ? ' - ' + data.shift : '') + (data.result === 'in' ? ' (' + data.status + ')' : ''), data.photo);
            addLog(data.name + ' - ' + what + (late ? ' (Late)' : ''));
        }
        readySoon(3500);
    }

    el.connect.addEventListener('click', function () { start(false); });
    el.stop.addEventListener('click', stop);
    window.addEventListener('pagehide', function () {
        if (conn) { try { conn.writer.write(enc.encode('CANCEL\n')); } catch (e) {} }
    });
    window.addEventListener('beforeunload', function () { running = false; });

    // Open this page in its own small window, so it keeps running while the admin uses other pages.
    if (window.name === 'biotrack_scan') {
        el.popout.hidden = true;
    } else {
        el.popout.addEventListener('click', function () {
            window.open('attendance_scan.php?usb=1', 'biotrack_scan', 'width=560,height=800');
        });
    }

    // Reconnect by itself when the page opens (the browser remembers the scanner after the first time),
    // unless the person pressed Stop.
    var stopped = false;
    try { stopped = sessionStorage.getItem('scan_stopped') === '1'; } catch (e) {}
    if (!stopped && 'serial' in navigator) start(true);
})();
