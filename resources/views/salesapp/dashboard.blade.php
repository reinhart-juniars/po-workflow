@extends('layouts.salesapp', ['title' => 'Dashboard'])

@section('content')
    <div class="sales-page">
        <section class="dashboard-hero sales-hero">
            <div class="page-toolbar">
                <div class="min-w-0">
                    <h1 class="dashboard-hero-title">Sales Actual</h1>
                    <p class="dashboard-hero-subtitle">
                        Catat jumlah yang benar-benar terjual setelah delivery, lalu submit sebagai penjualan final.
                    </p>
                </div>
            </div>

            <form method="GET" action="{{ route('salesapp.dashboard') }}" class="sales-filter-grid">
                <div>
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" name="date_from" value="{{ $dateFrom->toDateString() }}" class="form-control">
                </div>
                <div>
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" name="date_to" value="{{ $dateTo->toDateString() }}" class="form-control">
                </div>
                <div>
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="all" @selected($status === 'all')>Semua</option>
                        <option value="draft" @selected($status === 'draft')>Draft</option>
                        <option value="submitted" @selected($status === 'submitted')>Submitted</option>
                    </select>
                </div>
                <div class="sales-filter-actions">
                    <button class="btn-primary w-full" type="submit">Terapkan</button>
                    <a href="{{ route('salesapp.dashboard') }}" class="btn-ghost w-full">Reset</a>
                </div>
            </form>
        </section>

        <section class="sales-stats-grid">
            <article class="sales-stat-card">
                <p class="stat-label">Draft</p>
                <p class="sales-stat-value text-amber-600">{{ number_format($draftCount) }}</p>
                <p class="stat-meta">Menunggu input hasil penjualan</p>
            </article>
            <article class="sales-stat-card">
                <p class="stat-label">Submitted</p>
                <p class="sales-stat-value text-emerald-600">{{ number_format($submittedCount) }}</p>
                <p class="stat-meta">Sudah final dan terkirim ke accounting</p>
            </article>
            <article class="sales-stat-card">
                <p class="stat-label">Total Sales Final</p>
                <p class="sales-stat-value-money text-brand-600">Rp
                    {{ number_format((float) $totalSalesFinal, 0, ',', '.') }}</p>
                <p class="stat-meta">Nilai penjualan yang sudah disubmit</p>
            </article>
            <article class="sales-stat-card">
                <p class="stat-label">Total Retur</p>
                <p class="sales-stat-value text-rose-600">{{ number_format((float) $totalReturns, 2, ',', '.') }}</p>
                <p class="stat-meta">Sisa delivery yang tidak terjual</p>
            </article>
        </section>

        <section class="table-shell sales-table sales-responsive-table">
            <div class="table-head">Daftar Sales Actual</div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Customer</th>
                            <th>DO</th>
                            <th>Detail Menu</th>
                            <th>Status</th>
                            <th>Total Actual</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($salesActuals as $actual)
                            <tr>
                                <td class="date-cell" data-label="Tanggal">
                                    {{ $actual->sales_date?->format('d M Y') }}
                                </td>
                                <td class="font-semibold text-slate-900" data-label="Customer">
                                    {{ $actual->customer->name ?? '-' }}
                                </td>
                                <td class="code-cell" data-label="DO">
                                    {{ $actual->deliveryOrder->do_code ?? 'Carry Forward' }}
                                </td>
                                <td data-label="Detail Menu">
                                    @if ($actual->items->isNotEmpty())
                                        <details>
                                            <summary class="cursor-pointer text-brand-600">
                                                Lihat {{ $actual->items->count() }} menu
                                            </summary>
                                            <ul class="mt-2 space-y-1 text-xs text-slate-600">
                                                @foreach ($actual->items as $item)
                                                    <li class="rounded-lg bg-slate-50 px-2 py-1.5">
                                                        <div class="font-semibold text-slate-800">
                                                            {{ $item->item_name }}
                                                            @if ($item->is_carry_forward)
                                                                <span class="font-medium text-amber-700">(carry forward)</span>
                                                            @endif
                                                        </div>
                                                        <div class="mt-0.5">
                                                            Delivery {{ number_format((float) $item->qty_delivery, 2, ',', '.') }}
                                                            | Actual {{ number_format((float) $item->qty_actual, 2, ',', '.') }}
                                                            | Retur {{ number_format((float) $item->qty_return, 2, ',', '.') }}
                                                        </div>
                                                        <div class="mt-0.5 font-semibold text-slate-700">
                                                            Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}
                                                            x {{ number_format((float) $item->qty_actual, 2, ',', '.') }}
                                                            = Rp {{ number_format((float) $item->subtotal_actual, 0, ',', '.') }}
                                                        </div>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @else
                                        <span class="text-xs text-slate-400">Tidak ada item</span>
                                    @endif
                                </td>
                                <td data-label="Status">
                                    <span
                                        class="status-badge {{ $actual->status === 'draft' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' }}">
                                        {{ ucfirst($actual->status) }}
                                    </span>
                                </td>
                                <td class="number-cell font-semibold" data-label="Total Actual">
                                    Rp {{ number_format((float) ($actual->total_actual_amount ?? 0), 0, ',', '.') }}
                                    <div class="mt-0.5 text-xs font-normal text-slate-500">
                                        Retur {{ number_format((float) ($actual->total_return_qty ?? 0), 2, ',', '.') }}
                                    </div>
                                </td>
                                <td class="action-cell" data-label="Aksi">
                                    <a href="{{ route('salesapp.actuals.edit', $actual) }}" class="btn-link">
                                        {{ $actual->status === 'draft' ? 'Edit' : 'Detail' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr class="empty-row">
                                <td colspan="7" class="full-cell py-8 text-center text-sm text-slate-500">
                                    Belum ada sales actual pada filter ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4">
                {{ $salesActuals->links() }}
            </div>
        </section>
    </div>
@endsection
