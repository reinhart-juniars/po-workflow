<?php

use App\Models\BalanceSheetAdjustment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membetulkan dua hal di data neraca awal:
 *
 * 1. "Koreksi Kas April" (30-04-2026) dibuat untuk membatalkan baris adjustment kas hasil
 *    impor Excel Jan-Mar 2026, karena uang yang sama sudah dicatat ulang sebagai
 *    OpeningBalance per 01-04-2026. Nilainya kelebihan Rp 20.775.961 dari yang seharusnya,
 *    sehingga Kas di Neraca lebih kecil dari saldo akhir Cashflow dengan selisih persis itu.
 *    Di sini nilainya dihitung ulang dari data, bukan di-hardcode, supaya idempoten.
 *
 * 2. Rekening "Mayapada" hanya hidup sebagai baris adjustment Excel, bukan sebagai
 *    CashAccount. Akibatnya saldonya ikut terhapus oleh koreksi April dan rekening itu
 *    tidak bisa menerima mutasi apa pun. Dipindahkan jadi CashAccount + OpeningBalance
 *    per 01-04-2026, sejajar dengan empat rekening lainnya.
 *
 * Seluruh blok dijaga oleh keberadaan baris "Koreksi Kas April", jadi migrasi ini no-op
 * di database bersih (termasuk database test).
 */
return new class extends Migration
{
    private const CORRECTION_LABEL = 'Koreksi Kas April';

    private const CORRECTION_DATE = '2026-04-30';

    private const MAYAPADA_LABEL = 'Mayapada';

    private const MAYAPADA_ADJUSTMENT_DATE = '2026-01-31';

    /**
     * Tanggal OpeningBalance kas yang menggantikan blok Excel Jan-Mar.
     */
    private const OPENING_BALANCE_DATE = '2026-04-01';

    /**
     * Nilai "Koreksi Kas April" sebelum diperbaiki, dipakai untuk down().
     */
    private const LEGACY_CORRECTION_AMOUNT = -445151865.00;

    public function up(): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $correction = $this->correctionRow();

        if (! $correction) {
            return;
        }

        DB::transaction(function () {
            $this->promoteMayapadaToCashAccount();
            $this->recalculateAprilCorrection();
        });
    }

    public function down(): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $correction = $this->correctionRow();

        if (! $correction) {
            return;
        }

        DB::transaction(function () {
            $this->demoteMayapadaBackToAdjustment();

            DB::table('balance_sheet_adjustments')
                ->where('account_group', BalanceSheetAdjustment::GROUP_CASH)
                ->where('label', self::CORRECTION_LABEL)
                ->whereDate('adjustment_date', self::CORRECTION_DATE)
                ->update([
                    'amount' => self::LEGACY_CORRECTION_AMOUNT,
                    'updated_at' => now(),
                ]);
        });
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('balance_sheet_adjustments')
            && Schema::hasTable('cash_accounts')
            && Schema::hasTable('opening_balances');
    }

    private function correctionRow(): ?object
    {
        return DB::table('balance_sheet_adjustments')
            ->where('account_group', BalanceSheetAdjustment::GROUP_CASH)
            ->where('label', self::CORRECTION_LABEL)
            ->whereDate('adjustment_date', self::CORRECTION_DATE)
            ->first();
    }

    /**
     * Pindahkan Mayapada dari baris adjustment menjadi CashAccount + OpeningBalance.
     * Aman dijalankan ulang: tiap langkah dilewati kalau hasilnya sudah ada.
     */
    private function promoteMayapadaToCashAccount(): void
    {
        $adjustment = DB::table('balance_sheet_adjustments')
            ->where('account_group', BalanceSheetAdjustment::GROUP_CASH)
            ->where('label', self::MAYAPADA_LABEL)
            ->whereDate('adjustment_date', self::MAYAPADA_ADJUSTMENT_DATE)
            ->first();

        if (! $adjustment) {
            return;
        }

        $now = now();

        $cashAccountId = DB::table('cash_accounts')->where('name', self::MAYAPADA_LABEL)->value('id');

        if (! $cashAccountId) {
            $cashAccountId = DB::table('cash_accounts')->insertGetId([
                'name' => self::MAYAPADA_LABEL,
                'type' => 'bank',
                'description' => 'Dipindahkan dari adjustment neraca Excel 31-01-2026 menjadi akun kas.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $hasOpeningBalance = DB::table('opening_balances')
            ->where('type', 'cash')
            ->where('reference_id', $cashAccountId)
            ->exists();

        if (! $hasOpeningBalance) {
            DB::table('opening_balances')->insert([
                'balance_date' => self::OPENING_BALANCE_DATE,
                'type' => 'cash',
                'reference_id' => $cashAccountId,
                'amount' => round((float) $adjustment->amount, 2),
                'description' => 'Saldo awal dari adjustment neraca Excel 31-01-2026.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('balance_sheet_adjustments')->where('id', $adjustment->id)->delete();
    }

    /**
     * Set "Koreksi Kas April" supaya persis membatalkan seluruh adjustment kas
     * sebelum tanggal OpeningBalance (blok Excel Jan-Mar), tidak lebih dan tidak kurang.
     */
    private function recalculateAprilCorrection(): void
    {
        $excelBlockTotal = round((float) DB::table('balance_sheet_adjustments')
            ->where('account_group', BalanceSheetAdjustment::GROUP_CASH)
            ->whereDate('adjustment_date', '<', self::OPENING_BALANCE_DATE)
            ->sum('amount'), 2);

        DB::table('balance_sheet_adjustments')
            ->where('account_group', BalanceSheetAdjustment::GROUP_CASH)
            ->where('label', self::CORRECTION_LABEL)
            ->whereDate('adjustment_date', self::CORRECTION_DATE)
            ->update([
                'amount' => -$excelBlockTotal,
                'notes' => 'Membatalkan adjustment kas hasil impor Excel Jan-Mar 2026 yang sudah digantikan OpeningBalance per '.self::OPENING_BALANCE_DATE.'.',
                'updated_at' => now(),
            ]);
    }

    private function demoteMayapadaBackToAdjustment(): void
    {
        $cashAccountId = DB::table('cash_accounts')->where('name', self::MAYAPADA_LABEL)->value('id');

        if (! $cashAccountId) {
            return;
        }

        $openingBalance = DB::table('opening_balances')
            ->where('type', 'cash')
            ->where('reference_id', $cashAccountId)
            ->first();

        if (! $openingBalance) {
            return;
        }

        $hasMovements = DB::table('purchase_orders')->where('cash_account_id', $cashAccountId)->exists()
            || DB::table('other_incomes')->where('cash_account_id', $cashAccountId)->exists()
            || DB::table('cash_outs')->where('cash_account_id', $cashAccountId)->exists();

        $now = now();

        DB::table('balance_sheet_adjustments')->insert([
            'adjustment_date' => self::MAYAPADA_ADJUSTMENT_DATE,
            'account_group' => BalanceSheetAdjustment::GROUP_CASH,
            'label' => self::MAYAPADA_LABEL,
            'amount' => round((float) $openingBalance->amount, 2),
            'notes' => 'Excel',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('opening_balances')->where('id', $openingBalance->id)->delete();

        // Rekening hanya dihapus kalau belum dipakai mutasi apa pun; kalau sudah,
        // dibiarkan hidup supaya tidak memutus foreign key.
        if (! $hasMovements) {
            DB::table('cash_accounts')->where('id', $cashAccountId)->delete();
        }
    }
};
