/* BioTrack AI Assistant - chat widget (admin only). */
(function () {
    var root = document.getElementById('btAi');
    if (!root) return;
    var fab = document.getElementById('btAiFab'), panel = document.getElementById('btAiPanel'),
        body = document.getElementById('btAiBody'), form = document.getElementById('btAiForm'),
        input = document.getElementById('btAiInput'), send = document.getElementById('btAiSend'),
        chips = document.getElementById('btAiChips');
    var KEY = 'bt_ai_chat', history = [], busy = false;

    function load() { try { var v = JSON.parse(sessionStorage.getItem(KEY) || '[]'); if (Array.isArray(v)) history = v; } catch (e) {} }
    function save() { try { sessionStorage.setItem(KEY, JSON.stringify(history.slice(-20))); } catch (e) {} }

    function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function inline(s) {
        return esc(s).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    }
    // tiny, safe markdown: paragraphs, - / * bullets, 1. lists, **bold**, `code`
    function md(text) {
        var out = '', list = null, lines = text.replace(/\r/g, '').split('\n');
        function close() { if (list) { out += '</' + list + '>'; list = null; } }
        lines.forEach(function (ln) {
            var b = ln.match(/^\s*[-*\u2022]\s+(.*)/), n = ln.match(/^\s*\d+[.)]\s+(.*)/);
            if (b) { if (list !== 'ul') { close(); out += '<ul>'; list = 'ul'; } out += '<li>' + inline(b[1]) + '</li>'; }
            else if (n) { if (list !== 'ol') { close(); out += '<ol>'; list = 'ol'; } out += '<li>' + inline(n[1]) + '</li>'; }
            else if (ln.trim() === '') { close(); }
            else { close(); out += '<p>' + inline(ln.replace(/^#+\s*/, '')) + '</p>'; }
        });
        close();
        return out;
    }

    function add(role, text) {
        var d = document.createElement('div');
        d.className = 'ai-msg ' + (role === 'user' ? 'user' : role === 'err' ? 'err' : 'bot');
        if (role === 'user' || role === 'err') d.textContent = text; else d.innerHTML = md(text);
        body.appendChild(d);
        body.scrollTop = body.scrollHeight;
        return d;
    }
    function welcome() {
        add('bot', 'Hi! I can tell you who is present, late or absent, summarize the week, or explain how to use BioTrack. What would you like to know?');
    }
    function render() {
        body.innerHTML = '';
        if (!history.length) { welcome(); chips.hidden = false; return; }
        history.forEach(function (m) { add(m.role === 'user' ? 'user' : 'bot', m.content); });
        chips.hidden = true;
    }

    function open() { root.classList.add('open'); panel.hidden = false; render(); setTimeout(function () { input.focus(); }, 60); }
    function close() { root.classList.remove('open'); panel.hidden = true; }
    fab.addEventListener('click', open);
    document.getElementById('btAiClose').addEventListener('click', close);
    document.getElementById('btAiClear').addEventListener('click', function () { if (busy) return; history = []; save(); render(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) close(); });

    function typing() {
        var t = document.createElement('div'); t.className = 'ai-typing'; t.innerHTML = '<i></i><i></i><i></i>';
        body.appendChild(t); body.scrollTop = body.scrollHeight; return t;
    }

    function ask(text) {
        text = (text || '').trim();
        if (!text || busy) return;
        busy = true; send.disabled = true; chips.hidden = true;
        history.push({ role: 'user', content: text }); save();
        add('user', text);
        input.value = ''; input.style.height = 'auto';
        var t = typing();
        fetch('ai_chat.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ messages: history.slice(-12) })
        }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected reply from the server.' }; }); })
          .then(function (j) {
              t.remove();
              if (j && j.ok) { history.push({ role: 'assistant', content: j.reply }); save(); add('bot', j.reply); }
              else { history.pop(); save(); add('err', (j && j.error) || 'Something went wrong.'); }
          })
          .catch(function () { t.remove(); history.pop(); save(); add('err', 'Could not reach the server. Is XAMPP running?'); })
          .then(function () { busy = false; send.disabled = false; input.focus(); });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(input.value); } });
    input.addEventListener('input', function () { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 110) + 'px'; });
    chips.addEventListener('click', function (e) { if (e.target.tagName === 'BUTTON') ask(e.target.textContent); });

    load();
})();
