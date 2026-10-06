<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payable;
use App\Models\PurchaseOrder;
use App\Models\PeriodClosing;
use App\Models\User;
use Carbon\Carbon;

class SuperadminDashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $currentPeriodClosing = PeriodClosing::query()
            ->where('period_month', $today->month)
            ->where('period_year', $today->year)
            ->first();
        $latestClosedPeriod = PeriodClosing::query()
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first();

        $stats = [
            'users' => User::count(),
            'active_customers' => Customer::query()->where('active', true)->count(),
            'due_receivables' => PurchaseOrder::query()
                ->openReceivable()
                ->whereDate('due_date', '<=', $today->toDateString())
                ->count(),
            'open_payables' => Payable::query()
                ->whereIn('status', ['unpaid', 'partial'])
                ->count(),
        ];

        return view('superadmin.dashboard', [
            'stats' => $stats,
            'currentPeriodLabel' => $this->periodLabel($today->month, $today->year),
            'currentPeriodClosed' => (bool) $currentPeriodClosing,
            'currentPeriodClosedAt' => optional($currentPeriodClosing?->closed_at)->format('d M Y H:i'),
            'latestClosedPeriodLabel' => $latestClosedPeriod
                ? $this->periodLabel((int) $latestClosedPeriod->period_month, (int) $latestClosedPeriod->period_year)
                : null,
        ]);
    }

    protected function periodLabel(int $month, int $year): string
    {
        $labels = [
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
        ];

        return ($labels[$month] ?? (string) $month) . ' ' . $year;
    }
}
