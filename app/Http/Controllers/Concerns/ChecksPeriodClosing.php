<?php

namespace App\Http\Controllers\Concerns;

use App\Models\PeriodClosing;
use Carbon\Carbon;

trait ChecksPeriodClosing
{
    protected function isPeriodClosed(string $date): bool
    {
        $parsed = Carbon::parse($date);

        return PeriodClosing::query()
            ->where('period_month', $parsed->month)
            ->where('period_year', $parsed->year)
            ->exists();
    }

    protected function periodStatusForRange(Carbon $dateFrom, Carbon $dateTo): array
    {
        $cursor = $dateFrom->copy()->startOfMonth();
        $end = $dateTo->copy()->startOfMonth();
        $periodPairs = [];

        while ($cursor->lte($end)) {
            $periodPairs[] = [
                'period_month' => (int) $cursor->month,
                'period_year' => (int) $cursor->year,
            ];

            $cursor->addMonth();
        }

        if ($periodPairs === []) {
            return [
                'has_closed_periods' => false,
                'closed_period_labels' => [],
                'message' => 'Semua periode pada rentang ini masih aktif.',
            ];
        }

        $closedPeriods = PeriodClosing::query()
            ->where(function ($query) use ($periodPairs) {
                foreach ($periodPairs as $pair) {
                    $query->orWhere(function ($nested) use ($pair) {
                        $nested->where('period_month', $pair['period_month'])
                            ->where('period_year', $pair['period_year']);
                    });
                }
            })
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get(['period_month', 'period_year']);

        $labels = $closedPeriods
            ->map(fn ($closing) => $this->periodLabel((int) $closing->period_month, (int) $closing->period_year))
            ->values()
            ->all();

        return [
            'has_closed_periods' => $labels !== [],
            'closed_period_labels' => $labels,
            'message' => $labels !== []
                ? 'Rentang filter memuat periode tertutup: ' . implode(', ', $labels) . '.'
                : 'Semua periode pada rentang ini masih aktif.',
        ];
    }

    protected function previousOpenPeriodWarning(?Carbon $referenceDate = null): ?array
    {
        $referenceDate ??= Carbon::now();
        $currentPeriod = $referenceDate->copy()->startOfMonth();
        $previousPeriod = $currentPeriod->copy()->subMonthNoOverflow();

        if ($this->isPeriodClosed($previousPeriod->toDateString())) {
            return null;
        }

        $currentPeriodLabel = $this->periodLabel((int) $currentPeriod->month, (int) $currentPeriod->year);
        $previousPeriodLabel = $this->periodLabel((int) $previousPeriod->month, (int) $previousPeriod->year);

        return [
            'current_period_label' => $currentPeriodLabel,
            'previous_period_label' => $previousPeriodLabel,
            'month' => (int) $previousPeriod->month,
            'year' => (int) $previousPeriod->year,
            'message' => sprintf(
                'Saat ini sudah masuk %s, tetapi periode %s masih aktif dan belum ditutup.',
                $currentPeriodLabel,
                $previousPeriodLabel
            ),
        ];
    }

    protected function monthLabel(int $month): string
    {
        return [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ][$month] ?? (string) $month;
    }

    protected function periodLabel(int $month, int $year): string
    {
        return $this->monthLabel($month) . ' ' . $year;
    }
}
