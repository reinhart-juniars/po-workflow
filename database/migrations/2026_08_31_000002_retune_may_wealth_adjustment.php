<?php

use App\Models\BalanceSheetAdjustment;
use App\Services\BalanceSheetService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menyetel ulang pasangan adjustment "Penyesuaian Kekayaan Mei" (31-05-2026) dan
 * pembaliknya (01-06-2026).
 *
 * Pasangan itu dulu dikalibrasi supaya Kekayaan per 31-05-2026 jatuh di angka target
 * klien. Setelah "Koreksi Kas April" dibetulkan (lihat migrasi 2026_08_31_000001),
 * kas April naik Rp 20.775.961 dan kalibrasi Mei ikut meleset sebesar itu.
 *
 * Nilainya dihitung dari laporan, bukan di-hardcode: adjustment kas menggeser
 * Kekayaan satu-lawan-satu (ekuitas neraca = total aset - kewajiban, dan selisihnya
 * jatuh ke baris "Penyesuaian Neraca" yang ikut dihitung sebagai Kekayaan), jadi
 *
 *     nilai_baru = nilai_sekarang + (target - kekayaan_sekarang)
 *
 * Pembalik 01-06-2026 selalu diset negatifnya supaya Juni dan seterusnya netral.
 */
return new class extends Migration
{
    private const ADJUSTMENT_LABEL = 'Penyesuaian Kekayaan Mei';

    private const ADJUSTMENT_DATE = '2026-05-31';

    private const REVERSAL_LABEL = 'Pembalik Penyesuaian Kekayaan Mei';

    private const REVERSAL_DATE = '2026-06-01';

    /**
     * Kekayaan per 31-05-2026 yang disepakati dengan klien.
     */
    private const WEALTH_TARGET = 709683132.00;

    /**
     * Nilai pasangan adjustment sebelum disetel ulang, dipakai untuk down().
     */
    private const LEGACY_AMOUNT = -2067987.87;

    public function up(): void
    {
        if (! $this->adjustmentPairExists()) {
            return;
        }

        $report = app(BalanceSheetService::class)->buildReport(Carbon::parse(self::ADJUSTMENT_DATE));

        $currentAmount = round((float) DB::table('balance_sheet_adjustments')
            ->where('account_group', BalanceSheetAdjustment::GROUP_CASH)
            ->where('label', self::ADJUSTMENT_LABEL)
            ->whereDate('adjustment_date', self::ADJUSTMENT_DATE)
            ->value('amount'), 2);

        $retunedAmount = round($currentAmount + (self::WEALTH_TARGET - (float) $report['wealthAmount']), 2);

        if (abs($retunedAmount - $currentAmount) < 0.005) {
            return;
        }

        $this->writePair($retunedAmount, sprintf(
            'Penyesuaian saldo agar kekayaan per %s sesuai target klien (%s). Khusus Mei.',
            self::ADJUSTMENT_DATE,
            number_format(self::WEALTH_TARGET, 0, ',', '.')
        ));
    }

    public function down(): void
    {
        if (! $this->adjustmentPairExists()) {
            return;
        }

        $this->writePair(self::LEGACY_AMOUNT, 'Penyesuaian saldo agar kekayaan sesuai target klien (709.683.132). Khusus Mei.');
    }

    private function adjustmentPairExists(): bool
    {
        if (! Schema::hasTable('balance_sheet_adjustments')) {
            return false;
        }

        return $this->pairQuery(self::ADJUSTMENT_LABEL, self::ADJUSTMENT_DATE)->exists()
            && $this->pairQuery(self::REVERSAL_LABEL, self::REVERSAL_DATE)->exists();
    }

    private function pairQuery(string $label, string $date)
    {
        return DB::table('balance_sheet_adjustments')
            ->where('account_group', BalanceSheetAdjustment::GROUP_CASH)
            ->where('label', $label)
            ->whereDate('adjustment_date', $date);
    }

    private function writePair(float $amount, string $notes): void
    {
        DB::transaction(function () use ($amount, $notes) {
            $now = now();

            $this->pairQuery(self::ADJUSTMENT_LABEL, self::ADJUSTMENT_DATE)->update([
                'amount' => $amount,
                'notes' => $notes,
                'updated_at' => $now,
            ]);

            $this->pairQuery(self::REVERSAL_LABEL, self::REVERSAL_DATE)->update([
                'amount' => -$amount,
                'notes' => 'Pembalik koreksi kekayaan Mei (selisih hanya masalah Mei) agar tidak kebawa ke Juni dan seterusnya.',
                'updated_at' => $now,
            ]);
        });
    }
};
