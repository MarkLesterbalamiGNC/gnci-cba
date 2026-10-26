<?php
/**
 * fingerprint_functions.php
 *
 * An employee can now have MORE THAN ONE fingerprint (e.g. both thumbs and an
 * index finger, so a scan still works if one finger is cut or dirty).
 *
 * Every finger is one row in `employee_fingerprints`:
 *     employee_id  ->  employees.id
 *     scanner_id   ->  the slot (1, 2, 3 ...) that finger occupies on the Arduino sensor
 *
 * employees.fingerprint_uid / employees.fingerprint_enrolled are still kept up to
 * date (first finger / "has at least one finger") so the Dashboard, Employee list,
 * Export and the ADMS device code keep working exactly as before.
 */
require_once __DIR__ . '/../config/db.php';

// How many fingers one employee may have. Change this number if you want more/fewer.
if (!defined('FP_MAX_PER_EMPLOYEE')) define('FP_MAX_PER_EMPLOYEE', 10);

/**
 * Creates the table the first time it is needed and copies over the single
 * fingerprint every existing employee already has. Call it BEFORE any
 * beginTransaction() (CREATE TABLE ends a transaction in MySQL).
 */
function fp_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    if ($pdo->query("SHOW TABLES LIKE 'employee_fingerprints'")->fetchColumn()) return;

    $pdo->exec("CREATE TABLE employee_fingerprints (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        scanner_id INT NOT NULL,                       -- slot number on the Arduino fingerprint sensor
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fp_scanner (scanner_id),       -- one slot belongs to one finger only
        KEY idx_fp_employee (employee_id),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // One-time copy of the old single fingerprint_uid of each employee
    $pdo->exec("INSERT IGNORE INTO employee_fingerprints (employee_id, scanner_id)
                SELECT id, CAST(fingerprint_uid AS UNSIGNED) FROM employees
                WHERE fingerprint_uid REGEXP '^[0-9]+$' AND CAST(fingerprint_uid AS UNSIGNED) > 0");
}

/** Scanner IDs belonging to one employee, oldest first (this is the "Finger 1, 2, 3" order). */
function fp_list(PDO $pdo, int $empPk): array {
    fp_ensure_table($pdo);
    $st = $pdo->prepare("SELECT scanner_id FROM employee_fingerprints WHERE employee_id = ? ORDER BY id");
    $st->execute([$empPk]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Every scanner ID that is taken, with the owner's name: ['3' => 'Mark Lester', ...] (Recently Deleted included). */
function fp_used_map(PDO $pdo): array {
    fp_ensure_table($pdo);
    $out = [];
    $rows = $pdo->query("SELECT f.scanner_id, e.name FROM employee_fingerprints f JOIN employees e ON e.id = f.employee_id")->fetchAll();
    foreach ($rows as $r) $out[(string)$r['scanner_id']] = $r['name'];
    return $out;
}

/** Turns posted values into a clean list of unique positive integers. */
function fp_clean_uids($raw): array {
    $out = [];
    foreach ((array)$raw as $v) {
        $n = (int)$v;
        if ($n > 0 && !in_array($n, $out, true)) $out[] = $n;
    }
    return $out;
}

/** Keeps employees.fingerprint_uid / fingerprint_enrolled in step with the new table. */
function fp_sync_employee_columns(PDO $pdo, int $empPk): void {
    $list = fp_list($pdo, $empPk);
    $pdo->prepare("UPDATE employees SET fingerprint_uid = ?, fingerprint_enrolled = ? WHERE id = ?")
        ->execute([$list ? (string)$list[0] : null, $list ? 1 : 0, $empPk]);
}

/**
 * Makes the employee's fingers EXACTLY equal to $uids (adds new ones, drops missing ones).
 * Throws a PDOException ("Duplicate ...") if a scanner ID already belongs to someone else.
 * Run it inside the same transaction as the rest of the employee save.
 */
function fp_set_for_employee(PDO $pdo, int $empPk, array $uids): void {
    $current = fp_list($pdo, $empPk);

    foreach (array_diff($current, $uids) as $gone) {
        $pdo->prepare("DELETE FROM employee_fingerprints WHERE employee_id = ? AND scanner_id = ?")->execute([$empPk, $gone]);
    }
    $ins = $pdo->prepare("INSERT INTO employee_fingerprints (employee_id, scanner_id) VALUES (?, ?)");
    foreach ($uids as $uid) {
        if (!in_array($uid, $current, true)) $ins->execute([$empPk, $uid]);
    }
    fp_sync_employee_columns($pdo, $empPk);
}
