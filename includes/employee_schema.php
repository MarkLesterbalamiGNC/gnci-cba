<?php
/**
 * Makes sure employees.gender exists. Runs once per request, only does real work
 * the first time (when the column is missing), so no manual SQL step is needed.
 */
const BT_GENDERS = ['Male', 'Female'];

function bt_ensure_gender_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = $pdo->query("SHOW COLUMNS FROM employees LIKE 'gender'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN gender VARCHAR(10) DEFAULT NULL AFTER name");
        }
    } catch (Throwable $e) {
        error_log('bt_ensure_gender_column: ' . $e->getMessage());
    }
}
