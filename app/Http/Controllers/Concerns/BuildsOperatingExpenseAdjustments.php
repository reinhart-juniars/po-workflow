<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ProfitLossAdjustment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

trait BuildsOperatingExpenseAdjustments
{
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
            ->whereDate('adjustment_date', '>=', $dateFrom->toDateString())
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
