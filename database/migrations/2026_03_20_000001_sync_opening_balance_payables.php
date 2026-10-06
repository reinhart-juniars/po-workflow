<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opening_balances', function (Blueprint $table) {
            $table->string('supplier_name')->nullable()->after('reference_id');
        });

        Schema::table('payables', function (Blueprint $table) {
            $table->foreignId('opening_balance_id')->nullable()->after('id')->constrained('opening_balances')->nullOnDelete();
            $table->unique('opening_balance_id');
        });

        $now = now();
        $openingBalances = DB::table('opening_balances')
            ->where('type', 'payable')
            ->orderBy('id')
            ->get();

        foreach ($openingBalances as $openingBalance) {
            $supplierName = trim((string) ($openingBalance->description ?? ''));

            if ($supplierName === '') {
                $supplierName = 'Saldo Awal Hutang #' . $openingBalance->id;
            }

            DB::table('opening_balances')
                ->where('id', $openingBalance->id)
                ->update([
                    'supplier_name' => $supplierName,
                ]);

            DB::table('payables')->updateOrInsert(
                ['opening_balance_id' => $openingBalance->id],
                [
                    'transaction_date' => $openingBalance->balance_date,
                    'due_date' => null,
                    'supplier_name' => $supplierName,
                    'description' => $openingBalance->description ?: 'Saldo awal hutang',
                    'amount' => $openingBalance->amount,
                    'status' => 'unpaid',
                    'paid_at' => null,
                    'notes' => $openingBalance->description,
                    'is_adjustment' => false,
                    'adjustment_note' => null,
                    'adjusted_by' => null,
                    'created_by' => $openingBalance->created_by,
                    'updated_by' => $openingBalance->updated_by,
                    'created_at' => $openingBalance->created_at ?? $now,
                    'updated_at' => $openingBalance->updated_at ?? $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('payables', function (Blueprint $table) {
            $table->dropForeign(['opening_balance_id']);
            $table->dropUnique('payables_opening_balance_id_unique');
            $table->dropColumn('opening_balance_id');
        });

        Schema::table('opening_balances', function (Blueprint $table) {
            $table->dropColumn('supplier_name');
        });
    }
};
