<?php

declare(strict_types=1);

require_once __DIR__ . '/../../db/db.php';

if (!function_exists('system_position_config')) {
    function system_position_config(mysqli $connection): array
    {
        $defaults = [
            'table' => 'dh_positions',
            'id' => 'positionID',
            'name' => 'positionName',
            'teacher_column' => 'positionID',
        ];

        return $defaults;
    }
}

if (!function_exists('system_position_join')) {
    function system_position_join(mysqli $connection, string $teacher_alias = 't', string $position_alias = 'p'): array
    {
        $config = system_position_config($connection);
        $teacher_column = $config['teacher_column'] ?? 'positionID';
        $join = 'LEFT JOIN ' . $config['table'] . ' AS ' . $position_alias
            . ' ON ' . $teacher_alias . '.' . $teacher_column . ' = ' . $position_alias . '.' . $config['id'];

        return [
            'join' => $join,
            'name' => $position_alias . '.' . $config['name'],
        ];
    }
}

if (!function_exists('system_position_executive_id')) {
    function system_position_executive_id(mysqli $connection): ?int
    {
        if (!db_table_exists($connection, 'dh_positions')) {
            return null;
        }

        $row = db_fetch_one('SELECT positionID FROM dh_positions WHERE positionName LIKE ? LIMIT 1', 's', '%ผู้อำนวยการ%');

        if ($row && isset($row['positionID'])) {
            return (int) $row['positionID'];
        }

        $row = db_fetch_one('SELECT positionID FROM dh_positions WHERE positionID = 1 LIMIT 1');

        if ($row && isset($row['positionID'])) {
            return (int) $row['positionID'];
        }

        return null;
    }
}

if (!function_exists('system_position_deputy_ids')) {
    function system_position_deputy_ids(mysqli $connection): array
    {
        if (!db_table_exists($connection, 'dh_positions')) {
            return [];
        }

        $rows = db_fetch_all(
            'SELECT positionID
             FROM dh_positions
             WHERE positionName LIKE ?
             ORDER BY FIELD(positionID, 9, 2, 3, 4), positionID ASC',
            's',
            '%รองผู้อำนวยการ%'
        );
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) ($row['positionID'] ?? 0);
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if (!empty($ids)) {
            return $ids;
        }

        $fallback = db_fetch_all('SELECT positionID FROM dh_positions WHERE positionID IN (9,2,3,4) ORDER BY FIELD(positionID, 9, 2, 3, 4), positionID ASC');

        foreach ($fallback as $row) {
            $ids[] = (int) ($row['positionID'] ?? 0);
        }

        return array_values(array_unique(array_filter($ids)));
    }
}

if (!function_exists('system_position_budget_deputy_ids')) {
    function system_position_budget_deputy_ids(mysqli $connection): array
    {
        if (!db_table_exists($connection, 'dh_positions')) {
            return [];
        }

        $rows = db_fetch_all(
            'SELECT positionID
             FROM dh_positions
             WHERE positionName LIKE ?
                AND positionName LIKE ?
             ORDER BY FIELD(positionID, 3), positionID ASC',
            'ss',
            '%รองผู้อำนวยการ%',
            '%งบประมาณ%'
        );
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) ($row['positionID'] ?? 0);
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if (!empty($ids)) {
            return $ids;
        }

        $fallback = db_fetch_all('SELECT positionID FROM dh_positions WHERE positionID = 3 LIMIT 1');

        foreach ($fallback as $row) {
            $ids[] = (int) ($row['positionID'] ?? 0);
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
