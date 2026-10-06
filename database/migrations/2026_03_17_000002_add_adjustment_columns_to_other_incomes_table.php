<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('other_incomes')) {
            return;
        }

        Schema::table('other_incomes', function (Blueprint $table) {
            if (! Schema::hasColumn('other_incomes', 'is_adjustment')) {
                $table->boolean('is_adjustment')->default(false)->after('description');
            }

            if (! Schema::hasColumn('other_incomes', 'adjustment_note')) {
                $table->text('adjustment_note')->nullable()->after('is_adjustment');
            }

            if (! Schema::hasColumn('other_incomes', 'adjusted_by')) {
                $table->foreignId('adjusted_by')
                    ->nullable()
                    ->after('adjustment_note')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('other_incomes')) {
            return;
        }

        Schema::table('other_incomes', function (Blueprint $table) {
            if (Schema::hasColumn('other_incomes', 'adjusted_by')) {
                $table->dropConstrainedForeignId('adjusted_by');
            }

            $columns = array_filter([
                Schema::hasColumn('other_incomes', 'is_adjustment') ? 'is_adjustment' : null,
                Schema::hasColumn('other_incomes', 'adjustment_note') ? 'adjustment_note' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
