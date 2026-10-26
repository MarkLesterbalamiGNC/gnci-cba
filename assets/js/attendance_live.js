/**
 * BioTrack - live Scan Attendance screen.
 * The Arduino records Time In / Time Out by itself (device_punch.php).
 * This page only SHOWS what was recorded: it asks attendance_recent.php every 2 seconds.
 */
(function () {
    'use strict';
    function $(id) { return document.getElementById(id); }
    var el = { clock: $('scan-clock'), date: $('scan-date'), orb: $('scan-orb'), icon: $('scan-icon'),
               msg: $('scan-msg'), sub: $('scan-sub'), photoSlot: $('scan-photo-slot'), log: $('scan-log'),
               conn: $('scan-conn'), connText: $('scan-conn-text') };
    if (!el.log) return;

    var FP_ICON = el.icon.innerHTML;
    var lastKey = null, first = true, resetTimer = null;

    function tick() {
        var d = new Date();
        el.clock.textContent = d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
        el.date.textContent = d.toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }
    tick(); setInterval(tick, 1000);

    function setConn(text, on) { el.connText.textContent = text; el.conn.classList.toggle('on', !!on); }

    function waiting() {
        el.orb.className = 'scan-orb'; el.icon.innerHTML = FP_ICON;
        el.msg.textContent = 'Waiting for fingerprint scans...';
        el.sub.textContent = 'Place your finger on the scanner device.';
        el.photoSlot.innerHTML = '';
    }

    function showEvent(ev) {
        clearTimeout(resetTimer);
        var late = ev.type === 'in' && ev.status === 'Late';
        el.orb.className = 'scan-orb ' + (late ? 'late' : 'ok');
        el.icon.innerHTML = late ? '&#9203;' : '&#10004;';
        el.msg.textContent = ev.name;
        el.sub.textContent = (ev.type === 'in' ? 'Time In' : 'Time Out') + ' at ' + ev.time +
                             (ev.shift ? ' - ' + ev.shift : '') + (ev.type === 'in' && ev.status ? ' (' + ev.status + ')' : '');
        el.photoSlot.innerHTML = '';
        if (ev.photo) {
            var img = document.createElement('img');
            img.className = 'scan-photo'; img.alt = '';
            img.src = 'uploads/photos/' + encodeURIComponent(ev.photo);
            el.photoSlot.appendChild(img);
        }
        resetTimer = setTimeout(waiting, 6000);
    }

    function renderLog(events) {
        el.log.innerHTML = '';
        events.forEach(function (ev) {
            var li = document.createElement('li');
            var a = document.createElement('span'), b = document.createElement('span');
            a.textContent = ev.name + ' - ' + (ev.type === 'in' ? 'Time In' : 'Time Out') + (ev.type === 'in' && ev.status === 'Late' ? ' (Late)' : '');
            b.textContent = ev.time;
            li.appendChild(a); li.appendChild(b); el.log.appendChild(li);
        });
    }

    async function poll() {
        try {
            var res = await fetch('attendance_recent.php', { credentials: 'same-origin', cache: 'no-store' });
            var data = await res.json();
            if (!data.ok) { setConn(data.error || 'Signed out - reload this page', false); return; }
            setConn('Live - updates automatically', true);
            var evs = data.events || [];
            renderLog(evs);
            if (evs.length) {
                if (!first && evs[0].key !== lastKey) showEvent(evs[0]);
                lastKey = evs[0].key;
            }
            first = false;
        } catch (e) {
            setConn('Cannot reach the website', false);
        }
    }
    waiting(); poll(); setInterval(poll, 2000);
})();
