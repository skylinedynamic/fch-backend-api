<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the paths subscriptions:process-all walks on every run.
 *
 * Both tables carried nothing but a PRIMARY key, so the cron full-scanned them
 * twice a day, and once more per subscription for the open-invoice check:
 *
 *   subscriptions  status + subscription_end_date   which cycles are due
 *   invoices       subscription_id + status         is this subscriber's cycle open
 *   invoices       status                           which debts to attempt collecting
 *
 * Purely an access-path change - no column, value or behaviour is touched.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: array<int, string>}> */
    private array $indexes = [
        'subscriptions_billing_cycle_index' => ['subscriptions', ['status', 'subscription_end_date']],
        'invoices_subscription_status_index' => ['invoices', ['subscription_id', 'status']],
        'invoices_status_index' => ['invoices', ['status']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $name => [$table, $columns]) {
            if (!Schema::hasTable($table) || $this->indexExists($table, $name)) {
                continue;
            }

            // Skip rather than fail if a column was renamed or dropped elsewhere.
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    continue 2;
                }
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $name => [$table, $columns]) {
            if (!Schema::hasTable($table) || !$this->indexExists($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
