<?php
/**
 * Soft-delete ("Recently Deleted") support for employees.
 * Deleted employees are kept for TRASH_RETENTION_SECONDS, then purged
 * automatically the next time an admin opens the dashboard, Employee
 * list or Recently Deleted page.
 *
 * Every permanent deletion is first copied into the past_employee
 * table so admins can still look the person up afterwards (Archive page).
 */
require_once __DIR__ . '/../config/db.php';

if (!defined('TRASH_RETENTION_SECONDS')) define('TRASH_RETENTION_SECONDS', 43200*60); // Second to Minutes- 300Second = 5 minutes; 90Second = 1 min 30 sec; 1 = 1 second 
/** 43200*60 -> "30 days",(for the retention setting). */

function format_duration_seconds(int $sec): string {
    $units = ['day' => 86400, 'hour' => 3600, 'minute' => 60, 'second' => 1];
    $parts = [];
    foreach ($units as $name => $size) {
        $n = intdiv($sec, $size);
        $sec %= $size;
        if ($n > 0) $parts[] = $n . ' ' . $name . ($n === 1 ? '' : 's');
    }
    return $parts ? implode(' ', $parts) : '0 seconds';
}

/** Remaining time as "29d 23h", "4h 12m", "1m 30s" or "45s". */
function format_time_left(int $sec): string {
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($d > 0) return $d . 'd' . ($h ? ' ' . $h . 'h' : '');
    if ($h > 0) return $h . 'h' . ($m ? ' ' . $m . 'm' : '');
    if ($m > 0) return $m . 'm' . ($s ? ' ' . $s . 's' : '');
    return $s . 's';
}

function soft_delete_employee(int $id): bool {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE employees SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function restore_employee(int $id): bool {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE employees SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/**
 * Irreversibly removes the employee, their login account, photo and attendance rows.
 * A summary of the employee is saved to Past_employee first.
 * $how = 'manual' (admin clicked Delete Permanently) or 'auto' (retention period expired).
 */
function permanently_delete_employee(int $id, string $how = 'manual'): void {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT e.*, s.name AS schedule_name
         FROM employees e
         LEFT JOIN schedules s ON s.id = e.schedule_id
         WHERE e.id = ? AND e.deleted_at IS NOT NULL"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return;

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ?");
    $cnt->execute([$id]);
    $attendanceCount = (int)$cnt->fetchColumn();

    $pdo->beginTransaction();
    try {
        // Archive first; if this fails the whole deletion is rolled back
        $arc = $pdo->prepare(
            "INSERT INTO past_employee
                (original_id, employee_id, name, department, course, email, position, mobile_no,
                 schedule_name, attendance_count, deleted_at, purge_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $arc->execute([
            $row['id'], $row['employee_id'], $row['name'], $row['department'], $row['course'],
            $row['email'], $row['position'], $row['mobile_no'], $row['schedule_name'],
            $attendanceCount, $row['deleted_at'], $how === 'auto' ? 'auto' : 'manual',
        ]);

        $pdo->prepare("DELETE FROM accounts WHERE role = 'employee' AND employee_pk = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]); // attendance rows cascade
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (!empty($row['photo'])) {
        $path = __DIR__ . '/../uploads/photos/' . basename($row['photo']);
        if (is_file($path)) @unlink($path);
    }
}

/** Permanently deletes everything that has been in the trash longer than the retention period. */
function purge_expired_deleted_employees(): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE deleted_at IS NOT NULL AND deleted_at <= (NOW() - INTERVAL " . (int)TRASH_RETENTION_SECONDS . " SECOND)");
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) permanently_delete_employee((int)$id, 'auto');
    return count($ids);
}
