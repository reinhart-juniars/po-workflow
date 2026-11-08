<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ProfitLossAdjustment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

trait BuildsOperatingExpenseAdjustments
{
    /**
     * Adjustment tanpa kategori baru dihitung sejak tanggal ini. Sebelumnya V2
     * mengabaikannya (mis. beban Jan–Mar 2026 dari Excel), dan angka Laba Rugi
     * s/d Oktober 2026 yang sudah dilaporkan ke owner dipertahankan persis V2
     * (keputusan owner saat pindah V2 → V3, 8 Nov 2026).
     */
    public const STANDALONE_EXPENSE_ADJUSTMENT_START = '2026-11-01';

    /**
     * Adjustment beban operasional yang TIDAK punya expense_category_id.
     *
     * Adjustment bercategory ditempel ke baris kategorinya (lihat
     * mergeOperatingExpenseAdjustments). Yang tanpa kategori tidak punya baris
     * untuk ditempeli, jadi harus tampil sebagai baris pengeluaran tersendiri —
     * kalau tidak, nilainya hilang dari Total Pengeluaran dan Laba jadi
     * overstated sebesar adjustment tersebut.
     *
     * @return Collection<int, array{label: string, amount: float, meta: string}>
     */
    protected function standaloneOperatingExpenseAdjustmentRows(Carbon $dateFrom, Carbon $dateTo): Collection
    {
        return ProfitLossAdjustment::query()
            ->where('statement_group', ProfitLossAdjustment::GROUP_OPERATING_EXPENSE)
            ->whereNull('expense_category_id')
            ->whereDate('adjustment_date', '>=', max($dateFrom->toDateString(), self::STANDALONE_EXPENSE_ADJUSTMENT_START))
            ->whereDate('adjustment_date', '<=', $dateTo->toDateString())
            ->orderBy('adjustment_date')
            ->orderBy('id')
            ->get(['adjustment_date', 'label', 'amount', 'notes'])
            ->map(fn (ProfitLossAdjustment $row) => [
                'label' => (string) $row->label,
                'amount' => round((float) $row->amount, 2),
                'meta' => collect([
                    'Adjustment laba rugi per '.optional($row->adjustment_date)->format('d-m-Y'),
                    $row->notes,
                ])->filter()->implode(' | '),
            ])
            ->values()
            ->toBase();
    }
}
