<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../repositories/user-repository.php';
require_once __DIR__ . '/../modules/audit/logger.php';

if (!function_exists('auth_login_attempts_enabled')) {
    function auth_login_attempts_enabled(): bool
    {
        static $enabled = null;

        if ($enabled !== null) {
            return $enabled;
        }

        $enabled = db_table_exists(db_connection(), 'dh_login_attempts');

        return $enabled;
    }
}

if (!function_exists('auth_login_attempt_identity_exists')) {
    function auth_login_attempt_identity_exists(string $pid): bool
    {
        $pid = trim($pid);

        if ($pid === '') {
            return false;
        }

        $row = db_fetch_one('SELECT pID FROM teacher WHERE pID = ? LIMIT 1', 's', $pid);

        return $row !== null;
    }
}

if (!function_exists('auth_validate_credentials')) {
    function auth_validate_credentials(string $pid, string $password): array
    {
        $pid = trim($pid);
        $password = (string) $password;

        if ($pid === '' || $password === '') {
            return [false, null, 'กรุณากรอกเลขบัตรประชาชนและรหัสผ่านให้ครบถ้วน'];
        }

        $user = user_find_active_by_pid($pid);

        if (!$user) {
            return [false, null, 'เลขบัตรประชาชนหรือรหัสผ่านไม่ถูกต้อง'];
        }

        $stored = (string) ($user['passwordValue'] ?? '');

        if (!hash_equals($stored, $password)) {
            return [false, null, 'เลขบัตรประชาชนหรือรหัสผ่านไม่ถูกต้อง'];
        }

        return [true, $user, null];
    }
}

if (!function_exists('auth_check_lockout')) {
    function auth_check_lockout(string $pid, string $ip): array
    {
        if (!auth_login_attempts_enabled()) {
            return [true, null];
        }

        $pid = trim($pid);
        $ip = trim($ip);

        if ($pid === '' || $ip === '') {
            return [true, null];
        }

        $row = db_fetch_one('SELECT attemptCount, lockedUntil FROM dh_login_attempts WHERE pID = ? AND ipAddress = ? LIMIT 1', 'ss', $pid, $ip);

        if (!$row) {
            return [true, null];
        }

        $locked_until = $row['lockedUntil'] ?? null;

        if ($locked_until && strtotime((string) $locked_until) > time()) {
            return [false, 'บัญชีถูกล็อกชั่วคราว กรุณาลองใหม่อีกครั้งในภายหลัง'];
        }

        if ($locked_until) {
            db_execute(
                'UPDATE dh_login_attempts
                 SET attemptCount = 0, lockedUntil = NULL
                 WHERE pID = ? AND ipAddress = ? AND lockedUntil IS NOT NULL AND lockedUntil <= NOW()',
                'ss',
                $pid,
                $ip
            );
        }

        return [true, null];
    }
}

if (!function_exists('auth_record_login_failure')) {
    function auth_record_login_failure(string $pid, string $ip): void
    {
        if (!auth_login_attempts_enabled()) {
            return;
        }

        $pid = trim($pid);
        $ip = trim($ip);

        if ($pid === '' || $ip === '') {
            return;
        }

        // dh_login_attempts has FK to teacher.pID, so unknown pID cannot be inserted.
        if (!auth_login_attempt_identity_exists($pid)) {
            return;
        }

        $max_attempts = (int) app_env('AUTH_MAX_ATTEMPTS', 10);
        $lock_minutes = (int) app_env('AUTH_LOCK_MINUTES', 15);

        try {
            db_execute(
                'UPDATE dh_login_attempts
                 SET attemptCount = 0, lockedUntil = NULL
                 WHERE pID = ? AND ipAddress = ? AND lockedUntil IS NOT NULL AND lockedUntil <= NOW()',
                'ss',
                $pid,
                $ip
            );

            $sql = 'INSERT INTO dh_login_attempts (pID, ipAddress, attemptCount, lastAttemptAt, lockedUntil)
                    VALUES (?, ?, 1, NOW(), NULL)
                    ON DUPLICATE KEY UPDATE
                        attemptCount = attemptCount + 1,
                        lastAttemptAt = NOW(),
                        lockedUntil = IF(attemptCount + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NULL)';

            db_execute($sql, 'ssii', $pid, $ip, $max_attempts, $lock_minutes);
        } catch (Throwable $e) {
            error_log('auth_record_login_failure failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('auth_clear_login_failure')) {
    function auth_clear_login_failure(string $pid, string $ip): void
    {
        if (!auth_login_attempts_enabled()) {
            return;
        }

        $pid = trim($pid);
        $ip = trim($ip);

        if ($pid === '' || $ip === '') {
            return;
        }

        db_execute('DELETE FROM dh_login_attempts WHERE pID = ? AND ipAddress = ?', 'ss', $pid, $ip);
    }
}
