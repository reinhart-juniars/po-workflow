<?php

namespace Database\Seeders;

use App\Models\BalanceSheetAdjustment;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\ProfitLossAdjustment;
use App\Models\User;
use App\Services\BalanceSheetService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Koreksi data laporan keuangan (idempotent, aman dijalankan ulang).
 *
 * Melengkapi perbaikan LOGIC di BalanceSheetService / InventoryUsageService /
 * ProfitLossReportController dengan penyesuaian DATA agar laporan cocok dengan
 * rekap manual klien. Jalankan ulang setiap kali database di-reset:
 *
 *   php artisan db:seed --class=FinancialCorrectionSeeder
 *
 * Target (per 31 Mei 2026):
 *   - Penjualan Mei  = 175.047.000
 *   - Persediaan     = 121.821.726  (dari logic opname, tanpa data)
 *   - Aktiva Tetap   = 569.106.706
 *   - Kekayaan       = 709.683.132
 */
class FinancialCorrectionSeeder extends Seeder
{
    private const KEKAYAAN_TARGET = 709_683_132.0;
    private const PENJUALAN_REVENUE_ADJUSTMENT = -665_000.0;

    public function run(): void
    {
        $uid = User::query()->min('id');

        // 1. Inventaris & Peralatan = aktiva tetap (keluar dari beban P&L, masuk aktiva neraca).
        ExpenseCategory::query()
            ->whereIn('name', ['Inventaris', 'Peralatan'])
            ->update(['expense_mode' => ExpenseCategory::MODE_FIXED_ASSET]);

        $invId = ExpenseCategory::query()->where('name', 'Inventaris')->value('id');
        $perId = ExpenseCategory::query()->where('name', 'Peralatan')->value('id');
        $kpId = ExpenseCategory::query()->where('name', 'Keperluan Produksi')->value('id');

        // 2. Hapus koreksi persediaan manual lama (sudah digantikan logic opname).
        BalanceSheetAdjustment::query()->where('account_group', 'inventory')->delete();

        // 3. Hapus duplikat aktiva tetap "Peralatan Baru 325.590" (sudah auto dari cash out).
        BalanceSheetAdjustment::query()
            ->where('account_group', 'fixed_asset')
            ->where('label', 'Peralatan Baru')
            ->whereDate('adjustment_date', '2026-04-30')
            ->where('amount', 325_590)
            ->delete();

        // 4. Hapus 3 koreksi P&L April terkait Inventaris/Peralatan (sudah auto dari mode fixed_asset).
        ProfitLossAdjustment::query()
            ->where('statement_group', ProfitLossAdjustment::GROUP_OPERATING_EXPENSE)
            ->whereDate('adjustment_date', '2026-04-30')
            ->where(function ($q) use ($invId, $perId, $kpId) {
                $q->where('expense_category_id', $invId)
                    ->orWhere('expense_category_id', $perId)
                    ->orWhere(fn ($x) => $x
                        ->where('expense_category_id', $kpId)
                        ->where('label', 'Koreksi April')
                        ->where('amount', 15_000));
            })
            ->delete();

        // 5. Peralatan 15.000 (14 Apr) = biaya operasional (Keperluan Produksi), bukan aktiva.
        if ($perId && $kpId) {
            CashOut::query()
                ->whereDate('expense_date', '2026-04-14')
                ->where('amount', 15_000)
                ->where('expense_category_id', $perId)
                ->update(['expense_category_id' => $kpId]);
        }

        // 6. Penjualan Mei disesuaikan ke rekap manual klien (175.047.000).
        ProfitLossAdjustment::query()->updateOrCreate(
            [
                'statement_group' => ProfitLossAdjustment::GROUP_REVENUE,
                'adjustment_date' => '2026-05-31',
                'label' => 'Penyesuaian Penjualan Mei',
            ],
            [
                'expense_category_id' => null,
                'amount' => self::PENJUALAN_REVENUE_ADJUSTMENT,
                'notes' => 'Penyesuaian agar penjualan sesuai rekap manual klien (175.047.000).',
                'created_by' => $uid,
                'updated_by' => $uid,
            ]
        );

        // 7. Kekayaan disesuaikan ke target klien (709.683.132) lewat koreksi saldo kas.
        //    Selisih ~2 jt ini HANYA masalah Mei (kata klien), jadi dibalik lagi di
        //    1 Juni supaya tidak kebawa ke bulan-bulan berikutnya. Neraca bersifat
        //    kumulatif (adjustment_date <= report_date), jadi:
        //      - per 31 Mei : hanya koreksi -delta yang masuk  -> kekayaan = target
        //      - per >= Juni: koreksi -delta + pembalik +delta = 0 -> tidak terpengaruh
        BalanceSheetAdjustment::query()
            ->where('account_group', BalanceSheetAdjustment::GROUP_CASH)
            ->whereIn('label', ['Penyesuaian Kekayaan Mei', 'Pembalik Penyesuaian Kekayaan Mei'])
            ->delete();

        $wealth = app(BalanceSheetService::class)
            ->buildReport(Carbon::parse('2026-05-31')->endOfDay())['wealthAmount'];
        $delta = round($wealth - self::KEKAYAAN_TARGET, 2);

        if (abs($delta) >= 0.005) {
            BalanceSheetAdjustment::query()->create([
                'account_group' => BalanceSheetAdjustment::GROUP_CASH,
                'adjustment_date' => '2026-05-31',
                'label' => 'Penyesuaian Kekayaan Mei',
                'amount' => -$delta,
                'notes' => 'Penyesuaian saldo agar kekayaan sesuai target klien (709.683.132). Khusus Mei.',
                'created_by' => $uid,
                'updated_by' => $uid,
            ]);

            BalanceSheetAdjustment::query()->create([
                'account_group' => BalanceSheetAdjustment::GROUP_CASH,
                'adjustment_date' => '2026-06-01',
                'label' => 'Pembalik Penyesuaian Kekayaan Mei',
                'amount' => $delta,
                'notes' => 'Pembalik koreksi kekayaan Mei (selisih hanya masalah Mei) agar tidak kebawa ke Juni dan seterusnya.',
                'created_by' => $uid,
                'updated_by' => $uid,
            ]);
        }
    }
}
