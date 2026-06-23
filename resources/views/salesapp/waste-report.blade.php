@extends('layouts.salesapp', ['title' => 'Laporan Waste'])

@section('content')
    <div class="sales-page">
        <section class="dashboard-hero sales-hero">
            <div class="min-w-0">
                <h1 class="dashboard-hero-title">Laporan Waste</h1>
                <p class="dashboard-hero-subtitle">
                    Barang carry forward (retur hari sebelumnya) yang pada hari penjualan ternyata tidak layak
                    jual dan dibuang. Nilai ditampilkan dari cost (BB + OHC) dan harga jual.
                </p>
            </div>

            <form method="GET" action="{{ route('salesapp.reports.waste') }}"
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
                    <a href="{{ route('salesapp.reports.waste') }}" class="btn-ghost w-full">Reset</a>
                </div>
            </form>
        </section>

        @include('partials.report-export-actions', [
            'excelUrl' => route('salesapp.reports.waste.export.excel', request()->query()),
            'pdfUrl' => route('salesapp.reports.waste.export.pdf', request()->query()),
            'caption' => 'Export Laporan Waste mengikuti filter tanggal yang sedang dipilih.',
        ])

        <section class="sales-stats-grid-compact">
            <article class="sales-stat-card">
                <p class="stat-label">Total Qty Waste</p>
                <p class="sales-stat-value text-rose-600">{{ number_format((float) $totalWasteQty, 2, ',', '.') }}</p>
                <p class="stat-meta">Jumlah barang dibuang dari data final</p>
            </article>
            <article class="sales-stat-card">
                <p class="stat-label">Nilai Cost (BB + OHC)</p>
                <p class="sales-stat-value-money text-rose-600">Rp
                    {{ number_format((float) $totalWasteCost, 0, ',', '.') }}</p>
                <p class="stat-meta">Kerugian riil dari barang yang dibuang</p>
            </article>
            <article class="sales-stat-card">
                <p class="stat-label">Nilai Harga Jual</p>
                <p class="sales-stat-value-money text-slate-600">Rp
                    {{ number_format((float) $totalWasteSelling, 0, ',', '.') }}</p>
                <p class="stat-meta">Potensi pendapatan yang hilang</p>
            </article>
        </section>

        <section class="table-shell sales-table sales-responsive-table">
            <div class="table-head">Rincian Waste</div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Customer</th>
                            <th>Item</th>
                            <th>Qty Waste</th>
                            <th>Cost / Unit</th>
                            <th>Nilai Cost</th>
                            <th>Nilai Harga Jual</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($wasteItems as $item)
                            @php
                                $costPerUnit = (float) $item->raw_material_cost + (float) $item->overhead_cost;
                                $wasteCost = (float) $item->qty_waste * $costPerUnit;
                                $wasteSelling = (float) $item->qty_waste * (float) $item->unit_price;
                            @endphp
                            <tr>
                                <td class="date-cell" data-label="Tanggal">
                                    {{ $item->salesActual?->sales_date?->format('d M Y') }}</td>
                                <td data-label="Customer">{{ $item->salesActual?->customer?->name ?? '-' }}</td>
                                <td class="font-semibold text-slate-900" data-label="Item">{{ $item->item_name }}
                                    <span class="block text-xs font-normal text-slate-500">{{ $item->unit ?: '-' }}</span>
                                </td>
                                <td class="number-cell text-rose-600" data-label="Qty Waste">
                                    {{ number_format((float) $item->qty_waste, 2, ',', '.') }}</td>
                                <td class="number-cell" data-label="Cost / Unit">Rp
                                    {{ number_format($costPerUnit, 0, ',', '.') }}</td>
                                <td class="number-cell font-semibold text-rose-600" data-label="Nilai Cost">Rp
                                    {{ number_format($wasteCost, 0, ',', '.') }}</td>
                                <td class="number-cell" data-label="Nilai Harga Jual">Rp
                                    {{ number_format($wasteSelling, 0, ',', '.') }}</td>
                                <td data-label="Catatan">{{ $item->salesActual?->notes ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr class="empty-row">
                                <td colspan="8" class="full-cell py-8 text-center text-sm text-slate-500">
                                    Tidak ada barang waste pada periode ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
