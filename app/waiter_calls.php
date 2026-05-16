<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

if (!function_exists('waiter_calls_table_ready')) {
    function waiter_calls_table_ready(): bool
    {
        if (!function_exists('db_table_exists') || !db_table_exists('waiter_calls')) {
            return false;
        }

        if (!function_exists('db_column_exists')) {
            return true;
        }

        $requiredColumns = [
            'restaurant_id',
            'table_id',
            'order_id',
            'status',
            'created_at',
            'resolved_at',
        ];

        foreach ($requiredColumns as $column) {
            if (!db_column_exists('waiter_calls', $column)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('waiter_calls_require_table')) {
    function waiter_calls_require_table(bool $logMissing = true): bool
    {
        static $logged = false;

        $ready = waiter_calls_table_ready();
        if ($ready || !$logMissing || $logged) {
            return $ready;
        }

        $logged = true;
        error_log('WAITER_CALLS_SCHEMA_MISSING waiter_calls table or required columns are absent; feature disabled until migration is applied.');
        return false;
    }
}
