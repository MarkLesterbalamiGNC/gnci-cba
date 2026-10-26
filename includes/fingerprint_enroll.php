<?php
/**
 * Fingerprint pop-up for Add / Edit Employee (adds fingers, removes fingers).
 * Include it once, near the end of the page (after includes/footer.php) and
 * AFTER includes/fingerprint_panel.php has been rendered.
 *
 * Needs $pdo (config/db.php) and assets/js/fingerprint.js.
 */
require_once __DIR__ . '/fingerprint_functions.php';

// Every scanner ID already linked to an employee (Recently Deleted included), so the
// pop-up never hands out an ID that is taken and can say who owns a duplicate finger.
$fpUsed = fp_used_map($pdo);
$fpJs   = __DIR__ . '/../assets/js/fingerprint.js';
$fpVer  = is_file($fpJs) ? filemtime($fpJs) : 1;
?>
<style>
.fp-overlay { position:fixed; inset:0; background:rgba(0,0,0,.55); display:flex; align-items:center; justify-content:center; padding:20px; z-index:2100; }
.fp-overlay[hidden], .fp-box [hidden] { display:none !important; }
.fp-box { background:#fff; border-top:4px solid var(--yellow-accent); border-radius:var(--radius-lg); box-shadow:0 12px 40px rgba(0,0,0,.35); width:100%; max-width:420px; padding:20px 26px 22px; text-align:center; }
.fp-head { display:flex; align-items:center; justify-content:space-between; gap:10px; }
.fp-head h3 { margin:0; font-size:17px; color:var(--text-dark); }
.fp-conn { display:inline-flex; align-items:center; gap:6px; font-size:11.5px; color:var(--text-muted); }
.fp-conn i { width:8px; height:8px; border-radius:50%; background:#c3c9d4; display:inline-block; }
.fp-conn.on i { background:var(--success); }
.fp-mem { margin-top:10px; padding:6px 10px; background:#f4f6f9; border-radius:var(--radius); font-size:12px; color:var(--text-dark); }
.fp-orb { width:110px; height:110px; margin:20px auto 16px; border-radius:50%; background:#f4f4f6; border:3px solid #e2e2e7; box-shadow:0 10px 28px rgba(0,0,0,.10); display:flex; align-items:center; justify-content:center; font-size:50px; position:relative; }
.fp-orb.wait::after { content:''; position:absolute; inset:-3px; border-radius:50%; border:3px solid #141414; animation:fpPulse 1.4s ease-out infinite; }
.fp-orb.busy span { animation:fpBlink 1s ease-in-out infinite; }
.fp-orb.ok  { background:#e9f7ee; border-color:var(--success); color:var(--success); }
.fp-orb.err { background:#fdecea; border-color:var(--danger);  color:var(--danger); }
@keyframes fpPulse { 0% { transform:scale(1); opacity:.8; } 100% { transform:scale(1.5); opacity:0; } }
@keyframes fpBlink { 0%,100% { opacity:1; } 50% { opacity:.35; } }
.fp-msg { margin:0 0 4px; font-size:15.5px; font-weight:600; color:var(--text-dark); line-height:1.4; }
.fp-sub { margin:0; min-height:18px; font-size:13px; line-height:1.5; color:var(--text-muted); }
.fp-steps { list-style:none; display:flex; gap:6px; margin:18px 0 0; padding:0; }
.fp-steps li { flex:1; font-size:11px; color:var(--text-muted); }
.fp-steps li b { display:flex; width:26px; height:26px; margin:0 auto 4px; border-radius:50%; background:#e3e7ee; color:#2c3444; align-items:center; justify-content:center; font-size:12px; }
.fp-steps li.active { color:var(--text-dark); font-weight:600; }
.fp-steps li.active b { background:var(--yellow-accent); }
.fp-steps li.done b { background:var(--success); color:#fff; }
.fp-actions { display:flex; gap:10px; margin-top:20px; }
.fp-actions .btn { flex:1; }
.fp-cancel { background:#e3e7ee; color:#2c3444; }
.fp-cancel:hover { background:#d6dbe4; }
.fp-link { display:block; margin:12px auto 0; background:none; border:none; font-size:12.5px; color:var(--text-muted); text-decoration:underline; cursor:pointer; }
</style>

<div class="fp-overlay" id="fp-overlay" hidden>
    <div class="fp-box" role="dialog" aria-modal="true" aria-labelledby="fp-title">
        <div class="fp-head">
            <h3 id="fp-title">Fingerprints</h3>
            <span class="fp-conn" id="fp-conn"><i></i><span id="fp-conn-text">Not connected</span></span>
        </div>
        <div class="fp-mem" id="fp-mem" hidden></div>
        <div class="fp-orb" id="fp-orb"><span id="fp-icon"><?= bt_icon('fingerprint') ?></span></div>
        <p class="fp-msg" id="fp-msg"></p>
        <p class="fp-sub" id="fp-sub"></p>
        <ol class="fp-steps" id="fp-steps" hidden>
            <li data-step="1"><b>1</b>Place finger</li>
            <li data-step="2"><b>2</b>Lift finger</li>
            <li data-step="3"><b>3</b>Place again</li>
            <li data-step="4"><b>4</b>Saving</li>
        </ol>
        <div class="fp-actions">
            <button type="button" class="btn fp-cancel" id="fp-cancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="fp-action" hidden></button>
        </div>
        <button type="button" class="fp-link" id="fp-change" hidden>Use a different USB port</button>
        <button type="button" class="fp-link" id="fp-skip" hidden>Remove from the website only (leave it on the scanner)</button>
    </div>
</div>

<script>window.BIOTRACK_FP = {
    used: <?= json_encode((object)$fpUsed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    max: <?= (int)FP_MAX_PER_EMPLOYEE ?>,
    removeUrl: 'fingerprint_remove.php'
};</script>
<script src="assets/js/fingerprint.js?v=<?= (int)$fpVer ?>"></script>
