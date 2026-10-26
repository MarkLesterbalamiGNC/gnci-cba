-- =========================================================
-- BioTrack - A Fingerprint-Based Attendance System
-- COMBINED DATABASE FILE (replaces ALL of these):
--   database.sql, schedule_migration.sql, employee_housekeeping_migration.sql,
--   employee_trash_migration.sql, accounts_admin_fix_migration.sql,
--   force_password_change_migration.sql, default_admin.sql

CREATE DATABASE IF NOT EXISTS biotrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE biotrack;


-- =========================================================
-- PART 1: TABLES
-- =========================================================

-- ---------------------------------------------------------
-- Admins (legacy - still read by forgot_password.php)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    reset_token VARCHAR(100) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Accounts (role-based login for Admin and Employee)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role ENUM('admin','employee') NOT NULL,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    photo VARCHAR(255) DEFAULT NULL,                      -- admin profile picture
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = must choose a new password at next login (imported employees)
    admin_id INT DEFAULT NULL,                            -- legacy, not used for identifying the logged-in admin
    employee_pk INT DEFAULT NULL,                         -- employees.id for employee accounts
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_account_role_cred (role, username),
    UNIQUE KEY uniq_account_role_email (role, email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Schedules: a named work schedule made up of one or more
-- shifts per day (e.g. Morning 7-10AM + Afternoon 4-6PM).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,   -- active / inactive
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS schedule_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    shift_order TINYINT UNSIGNED NOT NULL,        -- 1 = first shift of the day, 2 = second, ...
    label VARCHAR(30) NOT NULL DEFAULT 'Shift',   -- e.g. Morning, Afternoon, Evening
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- minutes late still counted as "Present"
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_schedule_shift_order (schedule_id, shift_order)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Employees
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL UNIQUE,         -- shown in UI, also the login username
    name VARCHAR(100) NOT NULL,
    gender VARCHAR(10) DEFAULT NULL,                 -- Male / Female
    department VARCHAR(50) NOT NULL,
    course VARCHAR(10) DEFAULT NULL,                 -- BSIT / BSBA / BSA
    email VARCHAR(100) NOT NULL,
    position VARCHAR(50) NOT NULL,
    schedule_id INT DEFAULT NULL,                    -- which schedule punches are checked against
    mobile_no VARCHAR(20) NOT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    fingerprint_uid VARCHAR(50) DEFAULT NULL UNIQUE, -- ID registered on the biometric device
    fingerprint_enrolled TINYINT(1) DEFAULT 0,
    status TINYINT(1) DEFAULT 1,                     -- active / inactive
    deleted_at DATETIME DEFAULT NULL,                -- set = in "Recently Deleted" (30-day retention)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employees_deleted_at (deleted_at),
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Attendance: one row per employee, per day, per SHIFT
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    schedule_shift_id INT DEFAULT NULL,      -- which shift this row belongs to (NULL = no schedule)
    shift_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
    shift_label VARCHAR(30) DEFAULT NULL,    -- snapshot of the shift's label at punch time
    log_date DATE NOT NULL,
    time_in TIME DEFAULT NULL,
    time_out TIME DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'Present',    -- Present / Late / Absent
    source VARCHAR(20) DEFAULT 'device',     -- device / manual
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_shift_id) REFERENCES schedule_shifts(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_emp_day_shift (employee_id, log_date, shift_order)
) ENGINE=InnoDB;

-- =========================================================
-- PART 2: UPGRADE STEPS FOR AN EXISTING DATABASE
-- (do nothing on a new database, because the columns already exist)
-- =========================================================
ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS course VARCHAR(10) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS schedule_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL;

ALTER TABLE employees
    ADD INDEX IF NOT EXISTS idx_employees_deleted_at (deleted_at);

ALTER TABLE accounts
    ADD COLUMN IF NOT EXISTS photo VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS schedule_shift_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS shift_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS shift_label VARCHAR(30) DEFAULT NULL;

-- one row per shift per day (replaces the old one-row-per-day rule)
ALTER TABLE attendance ADD UNIQUE KEY IF NOT EXISTS uniq_emp_day_shift (employee_id, log_date, shift_order);
ALTER TABLE attendance DROP INDEX IF EXISTS uniq_emp_day;


-- =========================================================
-- PART 3: STARTER DATA
-- Two example schedules so the Add Employee form has something to pick.
-- Safe to edit or delete later from the Schedules page.
-- =========================================================
INSERT INTO schedules (name, description) VALUES
    ('Regular (8AM-5PM)', 'Single shift, standard office hours')
    ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO schedule_shifts (schedule_id, shift_order, label, time_start, time_end, grace_minutes)
SELECT id, 1, 'Whole Day', '08:00:00', '17:00:00', 15 FROM schedules WHERE name = 'Regular (8AM-5PM)'
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO schedules (name, description) VALUES
    ('Split Shift (Morning/Afternoon)', 'Two shifts per day, e.g. 7-10AM and 4-6PM')
    ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO schedule_shifts (schedule_id, shift_order, label, time_start, time_end, grace_minutes)
SELECT id, 1, 'Morning', '07:00:00', '10:00:00', 10 FROM schedules WHERE name = 'Split Shift (Morning/Afternoon)'
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO schedule_shifts (schedule_id, shift_order, label, time_start, time_end, grace_minutes)
SELECT id, 2, 'Afternoon', '16:00:00', '18:00:00', 10 FROM schedules WHERE name = 'Split Shift (Morning/Afternoon)'
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- ---------------------------------------------------------
-- Default admin account
--   Username : SuperAdmin
--   Password : M4sterAdm1n   (stored below as a bcrypt hash, never plain text)
-- Safe to run again: it will not create a duplicate.
-- Change the password anytime in Account Settings after signing in.
-- ---------------------------------------------------------
INSERT INTO accounts (role, username, email, password_hash, display_name, must_change_password)
SELECT 'admin', 'SuperAdmin', 'biotrack.gnci.cba@gmail.com',
       '$2y$12$iYDT.ypUeuoGyUlalIU.nOjo6lwTHWODvaXnNFy50NpWpy4kJrqz6',
       'SuperAdmin', 0
WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE role = 'admin' AND username = 'SuperAdmin');

-- Run this ONCE (phpMyAdmin > your database > SQL tab) BEFORE using the new files.
CREATE TABLE IF NOT EXISTS past_employee (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    original_id      INT NULL,
    employee_id      VARCHAR(50)  NOT NULL,
    name             VARCHAR(150) NOT NULL,
    department       VARCHAR(100) NULL,
    course           VARCHAR(50)  NULL,
    email            VARCHAR(150) NULL,
    position         VARCHAR(100) NULL,
    mobile_no        VARCHAR(30)  NULL,
    schedule_name    VARCHAR(150) NULL,
    attendance_count INT NOT NULL DEFAULT 0,
    deleted_at       DATETIME NULL,
    archived_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    purge_type       VARCHAR(10) NOT NULL DEFAULT 'manual'
);


-- =========================================================
-- PART 4: SEVERAL FINGERPRINTS PER EMPLOYEE
-- One row per finger. scanner_id = the slot on the Arduino sensor.
-- (The website also creates this table automatically the first time it is needed,
--  and copies each employee's existing fingerprint_uid into it.)
-- =========================================================
CREATE TABLE IF NOT EXISTS employee_fingerprints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    scanner_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fp_scanner (scanner_id),
    KEY idx_fp_employee (employee_id),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO employee_fingerprints (employee_id, scanner_id)
SELECT id, CAST(fingerprint_uid AS UNSIGNED) FROM employees
WHERE fingerprint_uid REGEXP '^[0-9]+$' AND CAST(fingerprint_uid AS UNSIGNED) > 0;


-- =========================================================
-- PART 5: LOGIN PROTECTION
-- Remembers failed sign-ins so repeated wrong passwords get locked out for 15 minutes.
-- (The website also creates this table automatically the first time it is needed.)
-- =========================================================
CREATE TABLE IF NOT EXISTS security_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(20) NOT NULL,
    subject VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_bucket_subject (bucket, subject, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =========================================================
-- PART 6: CLEAN-UP OF OLD TABLES (safe to run again)
-- The old email-alert log and SMS tables are no longer used by BioTrack.
-- (This replaces the separate drop_mail_log.sql and drop_sms_tables.sql files.)
-- =========================================================
DROP TABLE IF EXISTS mail_log;
DROP TABLE IF EXISTS sms_log;
DROP TABLE IF EXISTS sms_settings;
