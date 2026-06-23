@extends('layouts.salesapp', ['title' => 'Laporan Sales Actual'])

@section('content')
    <div class="sales-page">
        <section class="dashboard-hero sales-hero">
            <div class="min-w-0">
                <h1 class="dashboard-hero-title">Laporan Sales Final & Retur</h1>
                <p class="dashboard-hero-subtitle">
                    Pantau penjualan final yang sudah disubmit, termasuk retur yang perlu dibawa ke pengiriman
                    berikutnya.
                </p>
            </div>

            <form method="GET" action="{{ route('salesapp.reports.final-retur') }}"
                class="sales-filter-grid xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_220px]">
                <div>
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" name="date_from" value="{{ $dateFrom->toDateString() }}" class="form-control">
                </div>
                <div>
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" name="date_to" value="{{ $dateTo->toDateString() }}" class="form-control">
                </div>
                <div class="sales-filter-actions md:col-span-2 xl:col-span-1">
                    <button class="btn-primary w-full" type="submit">Terapkan</button>
                    <a href="{{ route('salesapp.reports.final-retur') }}" class="btn-ghost w-full">Reset</a>
                </div>
            </form>
        </section>

        <section class="sales-stats-grid-compact">
            <article class="sales-stat-card">
                <p class="stat-label">Total Sales Final</p>
                <p class="sales-stat-value-money text-brand-600">Rp
                    {{ number_format((float) $totalSalesFinal, 0, ',', '.') }}</p>
                <p class="stat-meta">Nilai actual yang sudah final</p>
            </article>
            <article class="sales-stat-card">
                <p class="stat-label">Total Retur</p>
                <p class="sales-stat-value text-rose-600">{{ number_format((float) $totalReturns, 2, ',', '.') }}</p>
                <p class="stat-meta">Qty tidak terjual dari data final</p>
            </article>
        </section>

        <section class="table-shell sales-table sales-responsive-table">
            <div class="table-head">Penjualan Final Submitted</div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Submitted</th>
                            <th>Sales Date</th>
                            <th>Customer</th>
                            <th>DO</th>
                            <th>Total Actual</th>
                            <th>Qty Retur</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($actuals as $actual)
                            <tr>
                                <td class="date-cell" data-label="Submitted">
                                    {{ $actual->submitted_at?->format('d M Y H:i') }}</td>
                                <td class="date-cell" data-label="Sales Date">{{ $actual->sales_date?->format('d M Y') }}
                                </td>
                                <td class="font-semibold text-slate-900" data-label="Customer">
                                    {{ $actual->customer->name ?? '-' }}</td>
                                <td class="code-cell" data-label="DO">
                                    {{ $actual->deliveryOrder->do_code ?? 'Carry Forward' }}</td>
                                <td class="number-cell font-semibold" data-label="Total Actual">Rp
                                    {{ number_format((float) ($actual->total_actual_amount ?? 0), 0, ',', '.') }}</td>
                                <td class="number-cell" data-label="Qty Retur">
                                    {{ number_format((float) ($actual->total_return_qty ?? 0), 2, ',', '.') }}</td>
                                <td class="action-cell" data-label="Detail"><a
                                        href="{{ route('salesapp.actuals.edit', $actual) }}" class="btn-link">Buka</a></td>
                            </tr>
                        @empty
                            <tr class="empty-row">
                                <td colspan="7" class="full-cell py-8 text-center text-sm text-slate-500">
                                    Belum ada penjualan final yang disubmit pada periode ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4">
                {{ $actuals->links() }}
            </div>
        </section>

        <section class="table-shell sales-table sales-responsive-table">
            <div class="table-head">Retur untuk Carry Forward</div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Customer</th>
                            <th>Item</th>
                            <th>Qty Retur</th>
                            <th>Carry Forward</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($returnItems as $item)
                            <tr>
                                <td class="date-cell" data-label="Tanggal">
                                    {{ $item->salesActual->submitted_at?->format('d M Y') }}</td>
                                <td data-label="Customer">{{ $item->salesActual->customer->name ?? '-' }}</td>
                                <td class="font-semibold text-slate-900" data-label="Item">{{ $item->item_name }}</td>
                                <td class="number-cell" data-label="Qty Retur">
                                    {{ number_format((float) $item->qty_return, 2, ',', '.') }}</td>
                                <td data-label="Carry Forward">
                                    @if ($item->carryForwardItem?->salesActual)
                                        Draft {{ $item->carryForwardItem->salesActual->sales_date?->format('d M Y') }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td data-label="Catatan">{{ $item->salesActual->notes ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr class="empty-row">
                                <td colspan="6" class="full-cell py-8 text-center text-sm text-slate-500">
                                    Tidak ada retur pada periode ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
