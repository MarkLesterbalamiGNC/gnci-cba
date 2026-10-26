<?php
/** Floating AI assistant (admin only). Included by includes/footer.php. */
if (($_SESSION['role'] ?? '') !== 'admin') return;
$aiV = fn($f) => @filemtime(dirname(__DIR__) . '/' . $f);
?>
<link rel="stylesheet" href="assets/css/ai.css?v=<?= $aiV('assets/css/ai.css') ?>">
<div class="ai-root no-print" id="btAi">
    <button type="button" class="ai-fab" id="btAiFab" aria-label="Open BioTrack Assistant" title="BioTrack Assistant">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/><path d="M19 15l.8 1.9 1.9.8-1.9.8L19 20.4l-.8-1.9-1.9-.8 1.9-.8z"/></svg>
    </button>

    <section class="ai-panel" id="btAiPanel" role="dialog" aria-label="BioTrack Assistant" hidden>
        <header class="ai-head">
            <img src="assets/img/gnclogo.png" alt="" class="ai-head-logo">
            <div class="ai-head-text"><b>BioTrack Assistant</b><span>Ask about attendance or how to use the system</span></div>
            <button type="button" class="ai-icon-btn" id="btAiClear" title="New chat" aria-label="New chat">&#8635;</button>
            <button type="button" class="ai-icon-btn" id="btAiClose" title="Close" aria-label="Close">&times;</button>
        </header>
        <div class="ai-body" id="btAiBody"></div>
        <div class="ai-chips" id="btAiChips">
            <button type="button">Who is late today?</button>
            <button type="button">Who has no time in yet?</button>
            <button type="button">Summarize this week</button>
            <button type="button">How do I add a schedule?</button>
        </div>
        <form class="ai-form" id="btAiForm" autocomplete="off">
            <textarea id="btAiInput" rows="1" maxlength="1500" placeholder="Ask anything..." aria-label="Message"></textarea>
            <button type="submit" class="ai-send" id="btAiSend" aria-label="Send">&#10148;</button>
        </form>
        <div class="ai-note">AI can make mistakes. Check important details in the Attendance List.</div>
    </section>
</div>
<script src="assets/js/ai_chat.js?v=<?= $aiV('assets/js/ai_chat.js') ?>"></script>
