<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/state.php';

if (!function_exists('memo_status_definitions')) {
    function memo_status_definitions(): array
    {
        return [
            MEMO_STATUS_DRAFT => [
                'label' => 'รอการเสนอแฟ้ม',
                'pill_variant' => 'pending',
                'badge_variant' => 'warning',
                'priority' => 1,
            ],
            MEMO_STATUS_IN_REVIEW => [
                'label' => 'กำลังพิจารณา',
                'pill_variant' => 'processing',
                'badge_variant' => 'warning',
                'priority' => 2,
            ],
            MEMO_STATUS_SUBMITTED => [
                'label' => 'รอพิจารณา',
                'pill_variant' => 'pending',
                'badge_variant' => 'warning',
                'priority' => 3,
            ],
            MEMO_STATUS_CANCELLED => [
                'label' => 'ยกเลิก',
                'pill_variant' => 'rejected',
                'badge_variant' => 'danger',
                'priority' => 4,
            ],
            MEMO_STATUS_RETURNED => [
                'label' => 'ตีกลับแก้ไข',
                'pill_variant' => 'rejected',
                'badge_variant' => 'danger',
                'priority' => 5,
            ],
            MEMO_STATUS_APPROVED_UNSIGNED => [
                'label' => 'ลงนามแล้ว',
                'pill_variant' => 'approved',
                'badge_variant' => 'success',
                'priority' => 6,
            ],
            MEMO_STATUS_SIGNED => [
                'label' => 'ลงนามแล้ว',
                'pill_variant' => 'approved',
                'badge_variant' => 'success',
                'priority' => 7,
            ],
            MEMO_STATUS_REJECTED => [
                'label' => 'ไม่อนุมัติ',
                'pill_variant' => 'rejected',
                'badge_variant' => 'danger',
                'priority' => 8,
            ],
        ];
    }
}

if (!function_exists('memo_status_meta')) {
    function memo_status_meta(?string $status): array
    {
        $status = strtoupper(trim((string) $status));
        $definitions = memo_status_definitions();

        return $definitions[$status] ?? [
            'label' => $status !== '' ? $status : '-',
            'pill_variant' => 'pending',
            'badge_variant' => 'neutral',
            'priority' => 99,
        ];
    }
}

if (!function_exists('memo_status_options')) {
    function memo_status_options(): array
    {
        $options = ['all' => 'ทั้งหมด'];

        foreach (memo_status_definitions() as $status => $meta) {
            $options[$status] = (string) ($meta['label'] ?? $status);
        }

        return $options;
    }
}

if (!function_exists('memo_status_sort_priority_map')) {
    function memo_status_sort_priority_map(): array
    {
        $priority_map = [];

        foreach (memo_status_definitions() as $status => $meta) {
            $priority_map[$status] = (int) ($meta['priority'] ?? 99);
        }

        return $priority_map;
    }
}

if (!function_exists('memo_status_order_case_sql')) {
    function memo_status_order_case_sql(string $column = 'm.status'): string
    {
        $column = trim($column);

        if ($column === '') {
            $column = 'm.status';
        }

        $parts = ["CASE {$column}"];

        foreach (memo_status_sort_priority_map() as $status => $priority) {
            $parts[] = "WHEN '" . addslashes((string) $status) . "' THEN " . (int) $priority;
        }

        $parts[] = 'ELSE 99';
        $parts[] = 'END';

        return implode(' ', $parts);
    }
}
