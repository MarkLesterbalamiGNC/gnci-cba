<?php
/**
 * Called by assets/js/fingerprint.js right after a finger has been deleted from
 * the Arduino sensor, so the website forgets that finger too.
 * POST: employee = employees.id, scanner_id = the slot that was cleared.
 * Answers with JSON: {"ok":true,"remaining":N}  or  {"ok":false,"error":"..."}
 */
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/fingerprint_functions.php';

header('Content-Type: application/json; charset=utf-8');

function fp_reply(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (($_SESSION['role'] ?? null) !== 'admin') fp_reply(['ok' => false, 'error' => 'Please sign in again.'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')   fp_reply(['ok' => false, 'error' => 'Invalid request.'], 405);

$empPk = (int)($_POST['employee'] ?? 0);
$uid   = (int)($_POST['scanner_id'] ?? 0);
if ($empPk < 1 || $uid < 1) fp_reply(['ok' => false, 'error' => 'Invalid fingerprint.'], 400);

fp_ensure_table($pdo);
$pdo->prepare("DELETE FROM employee_fingerprints WHERE employee_id = ? AND scanner_id = ?")->execute([$empPk, $uid]);
fp_sync_employee_columns($pdo, $empPk);

fp_reply(['ok' => true, 'remaining' => count(fp_list($pdo, $empPk))]);
