@extends('layouts.salesapp', ['title' => 'Laporan Sales Actual'])

@section('content')
    <div class="sales-page">
        <section class="dashboard-hero sales-hero">
            <div class="min-w-0">
                <h1 class="dashboard-hero-title">Laporan Sales Final & Retur</h1>
                <p class="dashboard-hero-subtitle">
                    Pantau penjualan final yang sudah disubmit, termasuk retur yang masuk Barang Sisa dan ke mana
                    Barang Sisa itu dijual atau dibuang.
                </p>
            </div>

            <x-table-toolbar inline class="mt-4" :action="route('salesapp.reports.final-retur')"
              :filters="[
                ['type' => 'date-range', 'label' => 'Periode', 'from' => 'date_from', 'to' => 'date_to',
                 'value' => [$dateFrom, $dateTo]],
              ]" />
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
                                    {{ $actual->deliveryOrder->do_code ?? 'Tanpa DO' }}</td>
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
            <div class="table-head">Retur &rarr; Barang Sisa</div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Customer</th>
                            <th>Item</th>
                            <th>Qty Retur</th>
                            <th>Dijual / Dibuang</th>
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
                                <td data-label="Dijual / Dibuang">
                                    @forelse ($item->leftoverSales as $sale)
                                        <div>
                                            {{ number_format((float) $sale->qty_delivery, 2, ',', '.') }} ke
                                            {{ $sale->salesActual?->customer?->name ?? '-' }}
                                            {{ $sale->salesActual?->sales_date?->format('d M Y') }}
                                            <span class="text-xs text-slate-500">({{ $sale->salesActual?->isSubmitted() ? 'final' : 'draft' }})</span>
                                        </div>
                                    @empty
                                    @endforelse
                                    @foreach ($item->leftoverDisposals as $disposal)
                                        <div class="text-rose-600">
                                            {{ number_format((float) $disposal->qty, 2, ',', '.') }} dibuang
                                            {{ $disposal->disposed_at?->format('d M Y') }}
                                        </div>
                                    @endforeach
                                    @if ($item->leftoverSales->isEmpty() && $item->leftoverDisposals->isEmpty())
                                        <span class="text-amber-700">Masih di Barang Sisa</span>
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
