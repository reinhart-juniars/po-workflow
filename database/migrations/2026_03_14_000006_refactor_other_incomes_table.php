<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('other_incomes')) {
            return;
        }

        $hasRows = DB::table('other_incomes')->exists();
        $defaultCategoryId = null;
        $defaultCashAccountId = null;

        if ($hasRows) {
            $defaultCategoryId = DB::table('income_categories')
                ->where('name', 'Lain-lain')
                ->value('id');

            if (! $defaultCategoryId) {
                $defaultCategoryId = (int) DB::table('income_categories')->insertGetId([
                    'name' => 'Lain-lain',
                    'description' => 'Kategori default hasil migrasi pemasukan lama.',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $defaultCashAccountId = DB::table('cash_accounts')->orderBy('id')->value('id');

            if (! $defaultCashAccountId) {
                $defaultCashAccountId = (int) DB::table('cash_accounts')->insertGetId([
                    'name' => 'Cash Migration',
                    'type' => 'cash',
                    'description' => 'Akun kas default hasil migrasi pemasukan lama.',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::create('other_incomes_tmp', function (Blueprint $table) {
            $table->id();
            $table->date('income_date');
            $table->foreignId('income_category_id')->constrained('income_categories')->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('income_date');
        });

        $legacyColumns = Schema::getColumnListing('other_incomes');
        $hasLegacyColumns = in_array('cash_in_date', $legacyColumns, true);

        $rows = DB::table('other_incomes')
            ->select([
                'id',
                DB::raw(($hasLegacyColumns ? 'cash_in_date' : 'income_date') . ' as income_date'),
                'amount',
                'description',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            DB::table('other_incomes_tmp')->insert([
                'id' => $row->id,
                'income_date' => $row->income_date,
                'income_category_id' => $defaultCategoryId,
                'cash_account_id' => $defaultCashAccountId,
                'amount' => $row->amount,
                'description' => $row->description,
                'created_by' => $row->created_by,
                'updated_by' => $row->updated_by,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('other_incomes');
        Schema::rename('other_incomes_tmp', 'other_incomes');
    }

    public function down(): void
    {
        if (! Schema::hasTable('other_incomes')) {
            return;
        }

        Schema::create('other_incomes_legacy', function (Blueprint $table) {
            $table->id();
            $table->date('cash_in_date');
            $table->decimal('amount', 15, 2);
            $table->string('source', 100)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('cash_in_date');
        });

        $rows = DB::table('other_incomes')
            ->leftJoin('income_categories', 'other_incomes.income_category_id', '=', 'income_categories.id')
            ->select([
                'other_incomes.id',
                'other_incomes.income_date',
                'other_incomes.amount',
                'other_incomes.description',
                'other_incomes.created_by',
                'other_incomes.updated_by',
                'other_incomes.created_at',
                'other_incomes.updated_at',
                'income_categories.name as source',
            ])
            ->orderBy('other_incomes.id')
            ->get();

        foreach ($rows as $row) {
            DB::table('other_incomes_legacy')->insert([
                'id' => $row->id,
                'cash_in_date' => $row->income_date,
                'amount' => $row->amount,
                'source' => $row->source,
                'description' => $row->description,
                'created_by' => $row->created_by,
                'updated_by' => $row->updated_by,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('other_incomes');
        Schema::rename('other_incomes_legacy', 'other_incomes');
    }
};
