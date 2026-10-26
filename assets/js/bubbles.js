/* BioTrack - swipeable profile bubbles on the dashboard stat cards.
   Every few seconds the current profile swipes out to the LEFT and the next one slides in from the right.
   You can also swipe/drag (left = next, right = previous) or click the bubble. */
(function () {
    document.querySelectorAll('.bubble-deck').forEach(function (deck) {
        var list = [];
        try { list = JSON.parse(deck.getAttribute('data-bubbles') || '[]'); } catch (e) {}
        if (!list.length) {
            deck.innerHTML = '<span class="bubble-empty">' + (deck.getAttribute('data-empty') || 'Nothing to show') + '</span>';
            deck.style.cursor = 'default';
            return;
        }

        var i = 0, timer = null, busy = false;

        function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

        // Inline sizing = safety net: the photo is ALWAYS a small circle even if a stylesheet is cached or missing.
        var AV  = 'width:56px;height:56px;min-width:56px;border-radius:50%;overflow:hidden;flex:none;';
        var IMG = 'width:100%;height:100%;max-width:none;object-fit:cover;border-radius:50%;display:block;';

        function slideHTML(p, animClass) {
            var face = p.photo ? '<img src="' + esc(p.photo) + '" alt="" draggable="false" style="' + IMG + '">'
                               : esc((p.name || '?').trim().charAt(0).toUpperCase());
            var dots = '';
            if (list.length > 1) {
                var max = Math.min(list.length, 10);
                for (var k = 0; k < max; k++) dots += '<i class="' + (k === i % max ? 'on' : '') + '"></i>';
            }
            return '<div class="bubble-slide ' + (animClass || '') + '">' +
                '<div class="bubble-avatar" style="' + AV + '">' + face + '</div>' +
                '<div class="bubble-info"><div class="bubble-name">' + esc(p.name) + '</div>' +
                '<div class="bubble-meta">' + esc(p.meta || '') + '</div>' +
                '<div class="bubble-dots">' + dots + '</div></div></div>';
        }
        function render(animClass) { deck.innerHTML = slideHTML(list[i], animClass); }

        // dir = 1  -> next profile, current one swipes out to the left
        // dir = -1 -> previous profile, current one swipes out to the right
        function go(dir) {
            if (list.length < 2 || busy) return;
            busy = true;
            var cur = deck.querySelector('.bubble-slide');
            if (cur) cur.classList.add(dir > 0 ? 'slide-out-left' : 'slide-out-right');
            setTimeout(function () {
                i = (i + dir + list.length) % list.length;
                render(dir > 0 ? 'slide-in-right' : 'slide-in-left');
                busy = false;
            }, 280);
        }
        function start() { stop(); if (list.length > 1) timer = setInterval(function () { go(1); }, 3000); }
        function stop()  { if (timer) { clearInterval(timer); timer = null; } }

        // pointer swipe (mouse + touch)
        var x0 = null, moved = false;
        deck.addEventListener('pointerdown', function (e) { x0 = e.clientX; moved = false; deck.classList.add('dragging'); stop(); });
        deck.addEventListener('pointermove', function (e) { if (x0 !== null && Math.abs(e.clientX - x0) > 8) moved = true; });
        deck.addEventListener('pointerup', function (e) {
            if (x0 === null) return;
            var dx = e.clientX - x0;
            x0 = null; deck.classList.remove('dragging');
            if (Math.abs(dx) > 35) go(dx < 0 ? 1 : -1);   // swipe left = next, swipe right = previous
            else if (!moved) go(1);                        // tap/click = next
            start();
        });
        deck.addEventListener('pointercancel', function () { x0 = null; deck.classList.remove('dragging'); start(); });
        deck.addEventListener('mouseleave', function () { if (x0 === null) start(); });

        render('slide-in-right');
        start();
    });
})();
