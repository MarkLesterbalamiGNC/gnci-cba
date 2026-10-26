<?php
/**
 * Full-page "enter your admin password to continue" screen.
 * Expects $confirm = [title, crumb, message, items(optional), confirm_msg(optional), action(optional), hidden[], button, button_class(optional), back, error].
 * hidden values may be arrays (sent as name[]), so several ticked rows can be carried through.
 * items (optional) is a list of strings shown under the message, e.g. the names about to be deleted.
 * button_class defaults to btn-danger (delete); use btn-primary for non-destructive actions like Restore.
 */
$e = fn($v) => htmlspecialchars((string)$v);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $e($confirm['title']) ?> | BioTrack</title>
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main">
        <?php include __DIR__ . '/topbar.php'; ?>
        <div class="content">
            <div class="page-head">
                <h1><?= $e($confirm['title']) ?></h1>
                <div class="breadcrumb"><a href="dashboard.php">Home</a> &rsaquo; <?= $e($confirm['crumb']) ?></div>
            </div>

            <?php if (!empty($confirm['error'])): ?>
                <div class="alert alert-error"><?= $e($confirm['error']) ?></div>
            <?php endif; ?>

            <div class="panel" style="max-width:520px;" data-admin-confirm-panel>
                <p style="margin-top:0;line-height:1.6;"><?= $e($confirm['message']) ?></p>
                <?php if (!empty($confirm['items'])): ?>
                    <ul style="list-style:disc;padding-left:22px;margin:0 0 14px;max-height:180px;overflow-y:auto;font-size:13px;line-height:1.7;">
                        <?php foreach ($confirm['items'] as $it): ?><li><?= $e($it) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p style="color:#8a94a6;font-size:13px;">For security, enter your admin password to confirm.</p>
                <form method="post" action="<?= $e($confirm['action'] ?? '') ?>">
                    <?php foreach (($confirm['hidden'] ?? []) as $k => $v): ?>
                        <?php if (is_array($v)): foreach ($v as $item): ?>
                            <input type="hidden" name="<?= $e($k) ?>[]" value="<?= $e($item) ?>">
                        <?php endforeach; else: ?>
                            <input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="field">
                        <label>Admin Password *</label>
                        <input type="password" name="admin_password" placeholder="Your admin password" required autofocus>
                    </div>
                    <div style="margin-top:16px;display:flex;gap:10px;">
                        <?php $danger = ($confirm['button_class'] ?? 'btn-danger') === 'btn-danger'; ?>
                        <button type="submit" class="btn <?= $e($confirm['button_class'] ?? 'btn-danger') ?>"<?php if ($danger): ?>
                                data-confirm-title="Are you sure?"
                                data-confirm="<?= $e($confirm['confirm_msg'] ?? 'Are you sure you want to delete this? This cannot be undone.') ?>"
                                data-confirm-btn="Yes, Delete"<?php endif; ?>><?= $e($confirm['button']) ?></button>
                        <a href="<?= $e($confirm['back']) ?>" class="btn" style="background:#e3e7ee;color:#2c3444;" data-modal-cancel>Cancel</a>
                    </div>
                </form>
            </div>

<?php include __DIR__ . '/footer.php'; ?>
