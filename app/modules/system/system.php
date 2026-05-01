<?php

declare(strict_types=1);

require_once __DIR__ . '/../../db/db.php';
require_once __DIR__ . '/positions.php';

if (!function_exists('system_get_dh_year')) {
    function system_get_dh_year(): int
    {
        $row = db_fetch_one('SELECT dh_year FROM thesystem ORDER BY ID DESC LIMIT 1');
        $year = $row ? (int) ($row['dh_year'] ?? 0) : 0;

        if ($year <= 0) {
            $year = (int) date('Y') + 543;
        }

        return $year;
    }
}

if (!function_exists('system_get_dh_status')) {
    function system_get_dh_status(): int
    {
        $row = db_fetch_one('SELECT dh_status FROM thesystem ORDER BY ID DESC LIMIT 1');
        $status = $row ? (int) ($row['dh_status'] ?? 0) : 0;

        if ($status <= 0) {
            $status = 1;
        }

        return $status;
    }
}

if (!function_exists('system_get_dh_version')) {
    function system_get_dh_version(): string
    {
        static $cached_version = null;

        if ($cached_version !== null) {
            return $cached_version;
        }

        $fallback = '1.0.0';
        $connection = db_connection();

        if (!db_column_exists($connection, 'thesystem', 'dh_version')) {
            $cached_version = $fallback;

            return $cached_version;
        }

        $row = db_fetch_one('SELECT dh_version FROM thesystem ORDER BY ID DESC LIMIT 1');
        $version = trim((string) ($row['dh_version'] ?? ''));

        $cached_version = $version !== '' ? $version : $fallback;

        return $cached_version;
    }
}

if (!function_exists('system_get_exec_duty')) {
    function system_get_exec_duty(): array
    {
        $connection = db_connection();
        $position = system_position_join($connection, 't', 'p');
        $sql = 'SELECT l.pID, l.dutyStatus, t.fName, ' . $position['name'] . ' AS positionName
            FROM dh_exec_duty_logs AS l
            INNER JOIN teacher AS t ON l.pID = t.pID
            ' . $position['join'] . '
            WHERE l.dutyStatus IN (1, 2) AND t.status = 1
            ORDER BY l.dutyLogID DESC
            LIMIT 1';
        $row = db_fetch_one($sql);

        if (!$row) {
            return [
                'pID' => '',
                'dutyStatus' => 0,
                'name' => '',
                'positionName' => '',
            ];
        }

        return [
            'pID' => (string) ($row['pID'] ?? ''),
            'dutyStatus' => (int) ($row['dutyStatus'] ?? 0),
            'name' => (string) ($row['fName'] ?? ''),
            'positionName' => (string) ($row['positionName'] ?? ''),
        ];
    }
}

if (!function_exists('system_get_director_pid')) {
    function system_get_director_pid(): ?string
    {
        $row = db_fetch_one('SELECT pID FROM teacher WHERE positionID = 1 AND status = 1 ORDER BY pID ASC LIMIT 1');

        if ($row) {
            return (string) ($row['pID'] ?? null);
        }

        $connection = db_connection();
        $director_id = system_position_executive_id($connection);

        if ($director_id !== null) {
            $row = db_fetch_one('SELECT pID FROM teacher WHERE positionID = ? AND status = 1 ORDER BY pID ASC LIMIT 1', 'i', $director_id);
        }

        return $row ? (string) ($row['pID'] ?? null) : null;
    }
}

if (!function_exists('system_get_acting_director_pid')) {
    function system_get_acting_director_pid(): ?string
    {
        $duty = system_get_exec_duty();

        if (($duty['dutyStatus'] ?? 0) === 2 && !empty($duty['pID'])) {
            return (string) $duty['pID'];
        }

        return null;
    }
}

if (!function_exists('system_get_current_director_pid')) {
    function system_get_current_director_pid(): ?string
    {
        $acting = system_get_acting_director_pid();

        if ($acting !== null && $acting !== '') {
            return $acting;
        }

        return system_get_director_pid();
    }
}
