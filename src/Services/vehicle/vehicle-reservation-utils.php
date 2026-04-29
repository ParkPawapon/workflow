<?php

declare(strict_types=1);

if (!function_exists('vehicle_reservation_get_table_columns')) {
    function vehicle_reservation_get_table_columns(mysqli $connection, string $table = 'dh_vehicle_bookings', bool $refresh = false): array
    {
        static $cached = [];
        $table = trim($table);

        if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return [];
        }

        if (!$refresh && isset($cached[$table])) {
            return $cached[$table];
        }

        $cached[$table] = [];
        $result = mysqli_query($connection, 'SHOW COLUMNS FROM `' . $table . '`');

        if ($result === false) {
            error_log('Database Error: ' . mysqli_error($connection));

            return $cached[$table];
        }

        while ($row = mysqli_fetch_assoc($result)) {
            if (!empty($row['Field'])) {
                $cached[$table][] = $row['Field'];
            }
        }

        mysqli_free_result($result);

        return $cached[$table];
    }
}

if (!function_exists('vehicle_reservation_has_column')) {
    function vehicle_reservation_has_column(array $columns, string $column): bool
    {
        return in_array($column, $columns, true);
    }
}

if (!function_exists('vehicle_reservation_ensure_other_passenger_columns')) {
    function vehicle_reservation_ensure_other_passenger_columns(mysqli $connection): array
    {
        $columns = vehicle_reservation_get_table_columns($connection, 'dh_vehicle_bookings', true);

        if (!vehicle_reservation_has_column($columns, 'otherPassengerCount')) {
            $sql = "ALTER TABLE `dh_vehicle_bookings` ADD COLUMN `otherPassengerCount` int(11) NOT NULL DEFAULT 0 AFTER `companionCount`";

            try {
                if (!mysqli_query($connection, $sql)) {
                    error_log('Database Error: ' . mysqli_error($connection));
                }
            } catch (mysqli_sql_exception $exception) {
                error_log('Database Exception: ' . $exception->getMessage());
            }
        }

        $columns = vehicle_reservation_get_table_columns($connection, 'dh_vehicle_bookings', true);

        if (!vehicle_reservation_has_column($columns, 'otherPassengerNames')) {
            $sql = "ALTER TABLE `dh_vehicle_bookings` ADD COLUMN `otherPassengerNames` text DEFAULT NULL AFTER `otherPassengerCount`";

            try {
                if (!mysqli_query($connection, $sql)) {
                    error_log('Database Error: ' . mysqli_error($connection));
                }
            } catch (mysqli_sql_exception $exception) {
                error_log('Database Exception: ' . $exception->getMessage());
            }
        }

        $columns = vehicle_reservation_get_table_columns($connection, 'dh_vehicle_bookings', true);

        if (!vehicle_reservation_has_column($columns, 'finalApproverPID')) {
            $after_column = 'approvedAt';

            if (vehicle_reservation_has_column($columns, 'assignedNote')) {
                $after_column = 'assignedNote';
            } elseif (vehicle_reservation_has_column($columns, 'assignedByPID')) {
                $after_column = 'assignedByPID';
            }

            $sql = "ALTER TABLE `dh_vehicle_bookings` ADD COLUMN `finalApproverPID` varchar(13) DEFAULT NULL AFTER `" . $after_column . "`";

            try {
                if (!mysqli_query($connection, $sql)) {
                    error_log('Database Error: ' . mysqli_error($connection));
                }
            } catch (mysqli_sql_exception $exception) {
                error_log('Database Exception: ' . $exception->getMessage());
            }
        }

        return vehicle_reservation_get_table_columns($connection, 'dh_vehicle_bookings', true);
    }
}
