@extends('layouts.salesapp', ['title' => 'Barang Sisa'])

@section('content')
    @php
        $qty = fn ($value) => number_format((float) $value, 2, ',', '.');
        $rupiah = fn ($value) => 'Rp ' . number_format((float) $value, 0, ',', '.');
    @endphp

    <div class="sales-page">
        <section class="dashboard-hero sales-hero">
            <div class="min-w-0">
                <h1 class="dashboard-hero-title">Barang Sisa</h1>
                <p class="dashboard-hero-subtitle">
                    Retur dari penjualan yang sudah disubmit. Barang Sisa boleh dijual ke customer mana pun lewat
                    Penjualan Barang Sisa, atau dibuang bila tidak layak jual. Nilainya dihitung dari HPP menu dan,
                    selama belum terjual, tercatat sebagai persediaan.
                </p>
            </div>
        </section>

        <section class="sales-stats-grid-compact">
            <article class="sales-stat-card">
                <p class="stat-label">Total Qty Barang Sisa</p>
                <p class="sales-stat-value text-amber-600">{{ $qty($totalQty) }}</p>
                <p class="stat-meta">Belum dijual dan belum dibuang</p>
            </article>
            <article class="sales-stat-card">
                <p class="stat-label">Nilai (HPP)</p>
                <p class="sales-stat-value-money text-brand-600">{{ $rupiah($totalValue) }}</p>
                <p class="stat-meta">Qty x HPP menu</p>
            </article>
        </section>

        <section class="table-shell sales-table sales-responsive-table">
            <div class="table-head">Stok Barang Sisa</div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Retur</th>
                            <th>Asal</th>
                            <th>Menu</th>
                            <th>Sisa</th>
                            <th>HPP / Unit</th>
                            <th>Nilai</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stock as $row)
                            <tr>
                                <td class="date-cell" data-label="Retur">
                                    {{ \Illuminate\Support\Carbon::parse($row['returned_at'])->format('d M Y') }}
                                </td>
                                <td data-label="Asal">
                                    {{ $row['customer_name'] ?? '-' }}
                                    <a href="{{ route('salesapp.actuals.edit', $row['sales_actual_id']) }}" class="block text-xs text-brand-600">
                                        Sales Actual #{{ $row['sales_actual_id'] }}
                                    </a>
                                </td>
                                <td class="font-semibold text-slate-900" data-label="Menu">
                                    {{ $row['item_name'] }}
                                    <span class="block text-xs font-normal text-slate-500">{{ $row['unit'] ?: '-' }}</span>
                                </td>
                                <td class="number-cell font-semibold text-amber-700" data-label="Sisa">
                                    {{ $qty($row['available_qty']) }}
                                    <span class="block text-xs font-normal text-slate-500">dari retur {{ $qty($row['qty_return']) }}</span>
                                </td>
                                <td class="number-cell" data-label="HPP / Unit">
                                    @if ($row['unit_cost'] > 0)
                                        {{ $rupiah($row['unit_cost']) }}
                                    @else
                                        <span class="text-rose-600">HPP menu kosong</span>
                                    @endif
                                </td>
                                <td class="number-cell font-semibold" data-label="Nilai">{{ $rupiah($row['value']) }}</td>
                                <td data-label="Aksi" class="min-w-72">
                                    <details>
                                        <summary class="cursor-pointer font-semibold text-brand-600">Jual</summary>
                                        <form method="POST" action="{{ route('salesapp.leftovers.sell', $row['entry_id']) }}" class="mt-2 space-y-2">
                                            @csrf
                                            <label class="form-label" for="sell-target-{{ $row['entry_id'] }}">Sales Actual tujuan (draft)</label>
                                            <select id="sell-target-{{ $row['entry_id'] }}" name="sales_actual_id" class="form-control" required>
                                                <option value="">Pilih customer &amp; tanggal</option>
                                                @foreach ($draftActuals as $draft)
                                                    <option value="{{ $draft->id }}">
                                                        {{ $draft->sales_date?->format('d M Y') }} - {{ $draft->customer->name ?? 'Tanpa Customer' }}
                                                        ({{ $draft->deliveryOrder->do_code ?? 'tanpa DO' }})
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="grid grid-cols-2 gap-2">
                                                <div>
                                                    <label class="form-label" for="sell-qty-{{ $row['entry_id'] }}">Qty</label>
                                                    <input id="sell-qty-{{ $row['entry_id'] }}" type="number" name="qty" step="0.01" min="0.01"
                                                        max="{{ $row['available_qty'] }}" value="{{ $row['available_qty'] }}"
                                                        class="form-control text-right tabular-nums" required>
                                                </div>
                                                <div>
                                                    <label class="form-label" for="sell-price-{{ $row['entry_id'] }}">Harga</label>
                                                    <input id="sell-price-{{ $row['entry_id'] }}" type="number" name="unit_price" step="0.01" min="0"
                                                        value="{{ $row['unit_price'] }}" class="form-control text-right tabular-nums">
                                                </div>
                                            </div>
                                            <button type="submit" class="btn-primary">Tambahkan ke Sales Actual</button>
                                        </form>
                                    </details>
                                    <details class="mt-2">
                                        <summary class="cursor-pointer font-semibold text-rose-600">Buang</summary>
                                        <form method="POST" action="{{ route('salesapp.leftovers.dispose', $row['entry_id']) }}" class="mt-2 space-y-2"
                                            onsubmit="return confirm('Catat Barang Sisa ini sebagai dibuang? Nilainya masuk HPP.')">
                                            @csrf
                                            <div class="grid grid-cols-2 gap-2">
                                                <div>
                                                    <label class="form-label" for="dispose-qty-{{ $row['entry_id'] }}">Qty</label>
                                                    <input id="dispose-qty-{{ $row['entry_id'] }}" type="number" name="qty" step="0.01" min="0.01"
                                                        max="{{ $row['available_qty'] }}" value="{{ $row['available_qty'] }}"
                                                        class="form-control text-right tabular-nums" required>
                                                </div>
                                                <div>
                                                    <label class="form-label" for="dispose-date-{{ $row['entry_id'] }}">Tanggal</label>
                                                    <input id="dispose-date-{{ $row['entry_id'] }}" type="date" name="disposed_at"
                                                        value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required>
                                                </div>
                                            </div>
                                            <label class="form-label" for="dispose-reason-{{ $row['entry_id'] }}">Alasan</label>
                                            <input id="dispose-reason-{{ $row['entry_id'] }}" type="text" name="reason" maxlength="255"
                                                placeholder="mis. basi, rusak di perjalanan" class="form-control" required>
                                            <button type="submit" class="btn-danger">Catat Dibuang</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr class="empty-row">
                                <td colspan="7" class="full-cell py-8 text-center text-sm text-slate-500">
                                    Tidak ada Barang Sisa. Retur dari Sales Actual yang disubmit akan muncul di sini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="table-shell sales-table sales-responsive-table">
            <div class="table-head">Dibuang 30 Hari Terakhir</div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Menu</th>
                            <th>Qty</th>
                            <th>Nilai (HPP)</th>
                            <th>Alasan</th>
                            <th>Dicatat</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentDisposals as $disposal)
                            <tr>
                                <td class="date-cell" data-label="Tanggal">{{ $disposal->disposed_at?->format('d M Y') }}</td>
                                <td class="font-semibold text-slate-900" data-label="Menu">
                                    {{ $disposal->sourceItem?->item_name }}
                                    <span class="block text-xs font-normal text-slate-500">
                                        retur {{ $disposal->sourceItem?->salesActual?->customer?->name ?? '-' }}
                                    </span>
                                </td>
                                <td class="number-cell text-rose-600" data-label="Qty">{{ $qty($disposal->qty) }}</td>
                                <td class="number-cell" data-label="Nilai (HPP)">
                                    {{ $rupiah((float) $disposal->qty * (float) $disposal->sourceItem?->leftoverUnitCost()) }}
                                </td>
                                <td data-label="Alasan">{{ $disposal->reason ?: '-' }}</td>
                                <td data-label="Dicatat">{{ $disposal->creator?->name ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr class="empty-row">
                                <td colspan="6" class="full-cell py-8 text-center text-sm text-slate-500">
                                    Belum ada Barang Sisa yang dibuang dalam 30 hari terakhir.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
