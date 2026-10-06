<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = [
            'purchase_orders' => ['delivery_date', 'status'],
            'spks' => ['scheduled_at', 'slot_type', 'status'],
            'delivery_orders' => ['scheduled_at', 'status'],
        ];

        foreach ($indexes as $table => $columns) {
            foreach ($columns as $column) {
                $this->safeAddIndex($table, $column);
            }
        }
    }

    public function down(): void
    {
        $indexes = [
            'purchase_orders' => ['delivery_date', 'status'],
            'spks' => ['scheduled_at', 'slot_type', 'status'],
            'delivery_orders' => ['scheduled_at', 'status'],
        ];

        foreach ($indexes as $table => $columns) {
            foreach ($columns as $column) {
                $this->safeDropIndex($table, $column);
            }
        }
    }

    private function safeAddIndex(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->index($column);
            });
        } catch (\Throwable $e) {
            // index sudah ada / db engine berbeda -> skip
        }
    }

    private function safeDropIndex(string $table, string $column): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $indexName = "{$table}_{$column}_index";

        try {
            Schema::table($table, function (Blueprint $t) use ($indexName) {
                $t->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            // index tidak ada / db engine berbeda -> skip
        }
    }
};
