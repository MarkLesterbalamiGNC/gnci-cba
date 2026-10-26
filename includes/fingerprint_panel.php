<?php
/**
 * The "Fingerprints" box on Add / Edit Employee: lists every finger the employee
 * has, with a Remove button each, plus an Add button.
 *
 * Set these BEFORE including:
 *   $fpPanelUids  = [1, 4, ...]  scanner IDs to show
 *   $fpPanelSaved = [1, ...]     the ones already stored in the database (the rest show "Not saved yet")
 *   $fpEmployeePk = employees.id (0 on the Add page)
 */
$fpPanelUids  = array_values(array_map('intval', $fpPanelUids ?? []));
$fpPanelSaved = array_map('intval', $fpPanelSaved ?? []);
$fpEmployeePk = (int)($fpEmployeePk ?? 0);
$fpMax        = (int)FP_MAX_PER_EMPLOYEE;
$fpCount      = count($fpPanelUids);
?>
<style>
.fp-panel .fp-count { font-size: 14px; font-weight: 600; }
.fp-list { list-style: none; margin: 14px auto 0; padding: 0; max-width: 560px; text-align: left; }
.fp-list li { display: flex; align-items: center; gap: 12px; padding: 9px 12px; margin-bottom: 8px; background: #fff;
              border: 1px solid var(--border-light); border-radius: var(--radius); color: var(--text-dark); }
.fp-list .fp-n   { font-weight: 600; }
.fp-list .fp-sid { flex: 1; color: var(--text-muted); font-size: 12px; }
.fp-list .fp-remove { padding: 6px 12px; font-size: 12px; }
.fp-panel .fp-hint { margin-top: 10px; font-size: 12px; color: var(--text-muted); }
.fp-panel #fp-open:disabled { opacity: .45; cursor: not-allowed; }
</style>

<div class="fingerprint-box fp-panel <?= $fpCount ? 'enrolled' : '' ?>" id="fp-panel" data-employee="<?= $fpEmployeePk ?>" data-max="<?= $fpMax ?>">
    <span class="fp-icon"><?= bt_icon('fingerprint') ?></span>
    <div class="fp-count" id="fp-count-text"><?= $fpCount ? $fpCount . ' fingerprint' . ($fpCount === 1 ? '' : 's') . ' enrolled' : 'No fingerprint enrolled yet.' ?></div>

    <ul class="fp-list" id="fp-list">
        <?php foreach ($fpPanelUids as $i => $uid): ?>
        <li data-uid="<?= $uid ?>" data-saved="<?= in_array($uid, $fpPanelSaved, true) ? 1 : 0 ?>">
            <span class="fp-n">Finger <?= $i + 1 ?></span>
            <span class="fp-sid">Scanner ID <?= $uid ?></span>
            <?php if (!in_array($uid, $fpPanelSaved, true)): ?><span class="pill pill-gray fp-unsaved">Not saved yet</span><?php endif; ?>
            <button type="button" class="btn btn-danger fp-remove">Remove</button>
            <input type="hidden" name="fingerprint_uids[]" value="<?= $uid ?>">
        </li>
        <?php endforeach; ?>
    </ul>

    <div style="margin-top:12px;">
        <button type="button" class="btn btn-dark" id="fp-open"><?= bt_icon('fingerprint', 'btn-ico') ?> <?= $fpCount ? 'Add Another Finger' : 'Add Fingerprint' ?></button>
    </div>
    <div class="fp-hint" id="fp-hint">You can add up to <?= $fpMax ?> fingers per employee. New fingers are kept when you save the employee.</div>
</div>
