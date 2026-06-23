@extends('layouts.adminapp', ['title' => 'Menu Tanpa HPP/OHC'])

@section('content')
    <section class="dashboard-hero">
        <div class="page-toolbar">
            <div>
                <h1 class="dashboard-hero-title">Menu Sudah Ada PO Tapi Belum Lengkap HPP / OHC</h1>
                <p class="dashboard-hero-subtitle">
                    Daftar menu yang sudah pernah masuk ke Purchase Order pada periode tanggal terpilih namun belum memiliki
                    Bahan Baku (HPP) atau Overhead Cost (OHC).
                    Lengkapi data biaya untuk memastikan perhitungan laba rugi akurat.
                </p>
            </div>
        </div>
    </section>

    <section class="form-shell mt-4">
        <form method="GET" action="{{ route('adminapp.reports.missing-costs') }}" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control">
                </div>
                <div>
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control">
                </div>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:max-w-md">
                <button class="btn-primary w-full" type="submit">Lihat</button>
                <a href="{{ route('adminapp.reports.missing-costs') }}" class="btn-ghost w-full text-center">Reset</a>
            </div>
            <p class="text-xs text-slate-500">
                Periode terpilih: <span class="font-semibold">{{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}</span>
                s/d <span class="font-semibold">{{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</span>
                (berdasarkan tanggal pembuatan PO).
            </p>
        </form>
    </section>

    @include('partials.report-export-actions', [
        'excelUrl' => route('adminapp.reports.missing-costs.export.excel', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]),
        'caption' => 'Export Excel mengikuti format Master Menu.',
    ])

    <section class="stats-grid mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="stat-card">
            <div class="stat-label">Total Menu Belum Lengkap</div>
            <div class="stat-value text-rose-600">{{ number_format($productsMissingCosts->count()) }}</div>
            <div class="mt-1 text-xs text-slate-500">Menu yang dipakai di PO pada periode terpilih tapi HPP/OHC kosong atau
                0.</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">PO Terdampak (max 50)</div>
            <div class="stat-value text-amber-600">{{ number_format($impactedOrders->count()) }}</div>
            <div class="mt-1 text-xs text-slate-500">PO pada periode terpilih yang memuat menu di atas.</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Pemakaian PO Item</div>
            <div class="stat-value text-sky-600">{{ number_format($productsMissingCosts->sum('po_item_count')) }}</div>
            <div class="mt-1 text-xs text-slate-500">Total baris PO item pada periode terpilih yang memakai menu belum
                lengkap.</div>
        </div>
    </section>

    <section class="table-shell mt-4">
        <div class="table-head">Menu yang Perlu Dilengkapi</div>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Menu</th>
                        <th>Satuan</th>
                        <th class="text-right">Bahan Baku (HPP)</th>
                        <th class="text-right">Overhead Cost (OHC)</th>
                        <th class="text-right">Harga Jual</th>
                        <th class="text-right">Dipakai di PO Item</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($productsMissingCosts as $product)
                        <tr>
                            <td class="font-semibold text-slate-800">
                                {{ $product->name }}
                                @if (filled($product->sku))
                                    <div class="text-[10px] font-mono text-slate-400">{{ $product->sku }}</div>
                                @endif
                            </td>
                            <td>{{ $product->unit }}</td>
                            <td
                                class="text-right {{ blank($product->raw_material_cost) || (float) $product->raw_material_cost == 0 ? 'text-rose-600 font-semibold' : '' }}">
                                {{ $product->raw_material_cost !== null ? 'Rp ' . number_format($product->raw_material_cost, 0, ',', '.') : 'Kosong' }}
                            </td>
                            <td
                                class="text-right {{ blank($product->overhead_cost) || (float) $product->overhead_cost == 0 ? 'text-rose-600 font-semibold' : '' }}">
                                {{ $product->overhead_cost !== null ? 'Rp ' . number_format($product->overhead_cost, 0, ',', '.') : 'Kosong' }}
                            </td>
                            <td class="text-right">Rp {{ number_format((float) $product->base_price, 0, ',', '.') }}</td>
                            <td class="text-right">{{ number_format($product->po_item_count) }}</td>
                            <td>
                                <a href="{{ route('adminapp.products.edit', $product) }}"
                                    class="text-sm font-semibold text-brand-600 hover:text-brand-500">
                                    Lengkapi
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-sm text-slate-500">
                                Bagus! Semua menu yang dipakai di PO pada periode ini sudah memiliki HPP &amp; OHC.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($impactedOrders->isNotEmpty())
        <section class="table-shell mt-4">
            <div class="table-head">PO pada Periode Terpilih yang Memakai Menu di Atas</div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Customer</th>
                            <th>Menu Bermasalah</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($impactedOrders as $order)
                            @php
                                $affectedItems = $order->items->filter(
                                    fn($item) => $productIds->contains($item->product_id),
                                );
                            @endphp
                            <tr>
                                <td class="font-mono text-xs">{{ $order->po_number }}</td>
                                <td>{{ $order->customer->name ?? '-' }}</td>
                                <td>
                                    <ul class="list-disc pl-5 text-xs text-slate-600 space-y-0.5">
                                        @foreach ($affectedItems as $item)
                                            <li>{{ $item->product->name ?? '-' }} &times;{{ (int) $item->qty }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td>
                                    <span class="status-badge bg-slate-100 text-slate-700">
                                        {{ \App\Support\UiLabel::purchaseOrderStatus($order->status) }}
                                    </span>
                                </td>
                                <td>{{ $order->created_at?->format('d M Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
