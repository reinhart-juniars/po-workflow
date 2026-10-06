@extends('layouts.salesapp', ['title' => 'Laporan Waste'])

@section('content')
    <div class="sales-page">
        <section class="dashboard-hero sales-hero">
            <div class="min-w-0">
                <h1 class="dashboard-hero-title">Laporan Waste</h1>
                <p class="dashboard-hero-subtitle">
                    Barang Sisa (retur) yang ternyata tidak layak jual dan dibuang -- saat dijual ulang atau
                    langsung dari halaman Barang Sisa. Nilai ditampilkan dari cost (BB + OHC) dan harga jual.
                </p>
            </div>

            <x-table-toolbar inline class="mt-4" :action="route('salesapp.reports.waste')"
              :filters="[
                ['type' => 'date-range', 'label' => 'Periode', 'from' => 'date_from', 'to' => 'date_to',
                 'value' => [$dateFrom, $dateTo]],
              ]" />
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
                        @forelse ($wasteRows as $row)
                            @php
                                $wasteCost = $row['cost_total'] ?? $row['qty'] * $row['cost_per_unit'];
                                $wasteSelling = $row['qty'] * $row['unit_price'];
                            @endphp
                            <tr>
                                <td class="date-cell" data-label="Tanggal">
                                    {{ $row['date']?->format('d M Y') }}</td>
                                <td data-label="Customer">{{ $row['customer_name'] ?? '-' }}</td>
                                <td class="font-semibold text-slate-900" data-label="Item">{{ $row['item_name'] }}
                                    <span class="block text-xs font-normal text-slate-500">{{ $row['unit'] ?: '-' }} | {{ $row['source'] }}</span>
                                </td>
                                <td class="number-cell text-rose-600" data-label="Qty Waste">
                                    {{ number_format($row['qty'], 2, ',', '.') }}</td>
                                <td class="number-cell" data-label="Cost / Unit">Rp
                                    {{ number_format($row['cost_per_unit'], 0, ',', '.') }}</td>
                                <td class="number-cell font-semibold text-rose-600" data-label="Nilai Cost">Rp
                                    {{ number_format($wasteCost, 0, ',', '.') }}</td>
                                <td class="number-cell" data-label="Nilai Harga Jual">Rp
                                    {{ number_format($wasteSelling, 0, ',', '.') }}</td>
                                <td data-label="Catatan">{{ $row['notes'] ?: '-' }}</td>
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
