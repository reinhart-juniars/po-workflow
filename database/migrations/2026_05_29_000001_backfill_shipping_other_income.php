<?php

use App\Models\OtherIncome;
use App\Models\PurchaseOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CATEGORY_NAME = 'Penjualan Lain-Lain';

    public function up(): void
    {
        if (! Schema::hasTable('purchase_orders')
            || ! Schema::hasTable('other_incomes')
            || ! Schema::hasColumn('purchase_orders', 'shipping_cost')) {
            return;
        }

        $now = now();
        $categoryId = $this->resolveCategoryId($now);
        $systemUserId = (int) (DB::table('users')->orderBy('id')->value('id') ?? 0) ?: null;

        $this->backfillReceivableShipping($categoryId, $systemUserId, $now);

        if (Schema::hasTable('sales_daily_closings') && Schema::hasTable('sales_actuals')) {
            $this->backfillCashClosingShipping($categoryId, $systemUserId, $now);
        }
    }

    public function down(): void
    {
        // Hapus baris OtherIncome yang dibuat backfill (yang punya source_type Penjualan Lain-Lain).
        DB::table('other_incomes')
            ->where('source_type', PurchaseOrder::class)
            ->where('description', 'like', 'Ongkir Pelunasan PO %')
            ->delete();

        DB::table('other_incomes')
            ->where('source_type', \App\Models\SalesDailyClosing::class)
            ->where('description', 'like', 'Ongkir Closing %')
            ->delete();
    }

    private function resolveCategoryId(Carbon $now): int
    {
        $existingId = DB::table('income_categories')->where('name', self::CATEGORY_NAME)->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) DB::table('income_categories')->insertGetId([
            'name' => self::CATEGORY_NAME,
            'description' => 'Kategori sistem untuk ongkos kirim PO yang ditagih ke customer.',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function backfillReceivableShipping(int $categoryId, ?int $systemUserId, Carbon $now): void
    {
        DB::table('purchase_orders')
            ->where('payment_type', 'receivable')
            ->whereNotNull('cash_received_at')
            ->whereNotNull('cash_account_id')
            ->where('shipping_cost', '>', 0)
            ->orderBy('id')
            ->select(['id', 'po_number', 'cash_received_at', 'cash_account_id', 'shipping_cost'])
            ->chunkById(200, function ($pos) use ($categoryId, $systemUserId, $now) {
                foreach ($pos as $po) {
                    $exists = DB::table('other_incomes')
                        ->where('source_type', PurchaseOrder::class)
                        ->where('source_id', $po->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('other_incomes')->insert([
                        'income_date' => Carbon::parse($po->cash_received_at)->toDateString(),
                        'income_category_id' => $categoryId,
                        'cash_account_id' => $po->cash_account_id,
                        'source_type' => PurchaseOrder::class,
                        'source_id' => $po->id,
                        'amount' => round((float) $po->shipping_cost, 2),
                        'description' => sprintf('Ongkir Pelunasan PO %s', $po->po_number ?? '#' . $po->id),
                        'created_by' => $systemUserId,
                        'updated_by' => $systemUserId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    private function backfillCashClosingShipping(int $categoryId, ?int $systemUserId, Carbon $now): void
    {
        DB::table('sales_daily_closings')
            ->orderBy('id')
            ->select(['id', 'closing_date', 'cash_in_date'])
            ->chunkById(50, function ($closings) use ($categoryId, $systemUserId, $now) {
                foreach ($closings as $closing) {
                    $existing = DB::table('other_incomes')
                        ->where('source_type', \App\Models\SalesDailyClosing::class)
                        ->where('source_id', $closing->id)
                        ->where('description', 'like', 'Ongkir Closing %')
                        ->exists();

                    if ($existing) {
                        continue;
                    }

                    $rows = DB::table('sales_actuals')
                        ->join('sales_actual_items', 'sales_actual_items.sales_actual_id', '=', 'sales_actuals.id')
                        ->leftJoin('purchase_order_items', 'purchase_order_items.id', '=', 'sales_actual_items.purchase_order_item_id')
                        ->leftJoin('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                        ->where('sales_actuals.sales_daily_closing_id', $closing->id)
                        ->where('purchase_orders.payment_type', 'cash')
                        ->whereNotNull('purchase_orders.cash_account_id')
                        ->where('purchase_orders.shipping_cost', '>', 0)
                        ->select([
                            'purchase_orders.id as po_id',
                            'purchase_orders.cash_account_id as cash_account_id',
                            'purchase_orders.shipping_cost as shipping_cost',
                        ])
                        ->distinct()
                        ->get();

                    $groupedByAccount = $rows
                        ->groupBy('cash_account_id')
                        ->map(fn ($items) => round((float) collect($items)->unique('po_id')->sum('shipping_cost'), 2))
                        ->filter(fn ($amount) => $amount > 0);

                    $incomeDate = Carbon::parse($closing->cash_in_date ?? $closing->closing_date)->toDateString();

                    foreach ($groupedByAccount as $cashAccountId => $amount) {
                        DB::table('other_incomes')->insert([
                            'income_date' => $incomeDate,
                            'income_category_id' => $categoryId,
                            'cash_account_id' => (int) $cashAccountId,
                            'source_type' => \App\Models\SalesDailyClosing::class,
                            'source_id' => $closing->id,
                            'amount' => $amount,
                            'description' => sprintf('Ongkir Closing %s', Carbon::parse($closing->closing_date)->format('d-m-Y')),
                            'created_by' => $systemUserId,
                            'updated_by' => $systemUserId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }
};
