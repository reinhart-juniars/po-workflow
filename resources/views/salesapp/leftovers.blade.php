@extends('layouts.salesapp', ['title' => 'Barang Sisa'])

@section('content')
    @php
        $qty = fn ($value) => number_format((float) $value, 2, ',', '.');
        $rupiah = fn ($value) => 'Rp ' . number_format((float) $value, 0, ',', '.');
        // HPP per satuan kecil (per gram) butuh desimal supaya tidak menyesatkan.
        $rupiahUnit = fn ($value) => 'Rp ' . number_format((float) $value, abs((float) $value) < 100 ? 2 : 0, ',', '.');
    @endphp

    <div class="sales-page">
        <section class="dashboard-hero sales-hero">
            <div class="min-w-0">
                <h1 class="dashboard-hero-title">Barang Sisa</h1>
                <p class="dashboard-hero-subtitle">
                    Retur dari penjualan yang sudah disubmit. Barang Sisa boleh dijual ke customer mana pun lewat
                    Penjualan Barang Sisa, dirinci per komponen (nasi, telur, ...) lalu dijual sesuai yang diambil,
                    atau dibuang bila tidak layak jual. Nilainya dihitung dari HPP menu dan, selama belum terjual,
                    tercatat sebagai persediaan.
                </p>
            </div>
        </section>

        <section class="sales-stats-grid-compact">
            <article class="sales-stat-card">
                <p class="stat-label">Total Qty Barang Sisa</p>
                <p class="sales-stat-value text-amber-600">{{ $qty($totalQty) }}</p>
                <p class="stat-meta">Porsi utuh yang belum dijual, dibuang, atau dirinci</p>
            </article>
            <article class="sales-stat-card">
                <p class="stat-label">Nilai (HPP)</p>
                <p class="sales-stat-value-money text-brand-600">{{ $rupiah($totalValue) }}</p>
                <p class="stat-meta">Porsi utuh x HPP menu + nilai komponen</p>
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
                            <tr>
                                <td colspan="7" class="full-cell" style="text-align: left;">
                                    <details>
                                        <summary class="cursor-pointer text-sm font-semibold text-slate-700">
                                            Rinci {{ $row['item_name'] }} per komponen (nasi, telur, ...)
                                        </summary>
                                        @include('salesapp.partials.leftover-breakdown-form', [
                                            'action' => route('salesapp.leftovers.breakdowns.store', $row['entry_id']),
                                            'method' => 'POST',
                                            'key' => 'new-' . $row['entry_id'],
                                            'unitCost' => $row['unit_cost'],
                                            'portionQty' => $row['available_qty'],
                                            'maxPortion' => $row['available_qty'],
                                            'components' => [],
                                            'withDate' => true,
                                            'notes' => '',
                                        ])
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

        @foreach ($breakdowns as $bd)
            <section class="table-shell sales-table sales-responsive-table">
                <div class="table-head flex flex-wrap items-center justify-between gap-2">
                    <span>
                        Rincian {{ $bd['origin_name'] }} · {{ $qty($bd['breakdown']->portion_qty) }} {{ $bd['unit'] ?: 'porsi' }}
                        <span class="font-normal text-slate-500">
                            · retur {{ $bd['customer_name'] ?? '-' }} · dirinci {{ $bd['breakdown']->broken_at?->format('d M Y') }}
                            · HPP {{ $rupiah($bd['breakdown']->portion_value) }}
                            @if ($bd['breakdown']->unallocatedValue() > 0)
                                · tidak terinci {{ $rupiah($bd['breakdown']->unallocatedValue()) }} (waste)
                            @endif
                        </span>
                    </span>
                    @if ($bd['breakdown']->notes)
                        <span class="text-xs font-normal text-slate-500">{{ $bd['breakdown']->notes }}</span>
                    @endif
                </div>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Komponen</th>
                                <th>Sisa</th>
                                <th>HPP / Satuan</th>
                                <th>Nilai</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bd['components'] as $component)
                                <tr>
                                    <td class="font-semibold text-slate-900" data-label="Komponen">
                                        {{ $component['name'] }}
                                        <span class="block text-xs font-normal text-slate-500">{{ $component['unit'] }}</span>
                                    </td>
                                    <td class="number-cell font-semibold text-amber-700" data-label="Sisa">
                                        {{ $qty($component['available_qty']) }}
                                        <span class="block text-xs font-normal text-slate-500">dari {{ $qty($component['qty']) }}</span>
                                    </td>
                                    <td class="number-cell" data-label="HPP / Satuan">{{ $rupiahUnit($component['unit_cost']) }}</td>
                                    <td class="number-cell font-semibold" data-label="Nilai">{{ $rupiah($component['value']) }}</td>
                                    <td data-label="Aksi" class="min-w-72">
                                        @if ($component['available_qty'] > 0)
                                            <details>
                                                <summary class="cursor-pointer font-semibold text-brand-600">Jual</summary>
                                                <form method="POST" action="{{ route('salesapp.leftovers.components.sell', $component['component_id']) }}" class="mt-2 space-y-2">
                                                    @csrf
                                                    <label class="form-label" for="csell-target-{{ $component['component_id'] }}">Sales Actual tujuan (draft)</label>
                                                    <select id="csell-target-{{ $component['component_id'] }}" name="sales_actual_id" class="form-control" required>
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
                                                            <label class="form-label" for="csell-qty-{{ $component['component_id'] }}">Qty</label>
                                                            <input id="csell-qty-{{ $component['component_id'] }}" type="number" name="qty" step="0.01" min="0.01"
                                                                max="{{ $component['available_qty'] }}" value="{{ $component['available_qty'] }}"
                                                                class="form-control text-right tabular-nums" required>
                                                        </div>
                                                        <div>
                                                            <label class="form-label" for="csell-price-{{ $component['component_id'] }}">Harga / {{ $component['unit'] }}</label>
                                                            <input id="csell-price-{{ $component['component_id'] }}" type="number" name="unit_price" step="0.01" min="0"
                                                                class="form-control text-right tabular-nums" required>
                                                        </div>
                                                    </div>
                                                    <button type="submit" class="btn-primary">Tambahkan ke Sales Actual</button>
                                                </form>
                                            </details>
                                            <details class="mt-2">
                                                <summary class="cursor-pointer font-semibold text-rose-600">Waste</summary>
                                                <form method="POST" action="{{ route('salesapp.leftovers.components.dispose', $component['component_id']) }}" class="mt-2 space-y-2"
                                                    onsubmit="return confirm('Catat komponen ini sebagai waste? Nilainya masuk HPP.')">
                                                    @csrf
                                                    <div class="grid grid-cols-2 gap-2">
                                                        <div>
                                                            <label class="form-label" for="cdispose-qty-{{ $component['component_id'] }}">Qty</label>
                                                            <input id="cdispose-qty-{{ $component['component_id'] }}" type="number" name="qty" step="0.01" min="0.01"
                                                                max="{{ $component['available_qty'] }}" value="{{ $component['available_qty'] }}"
                                                                class="form-control text-right tabular-nums" required>
                                                        </div>
                                                        <div>
                                                            <label class="form-label" for="cdispose-date-{{ $component['component_id'] }}">Tanggal</label>
                                                            <input id="cdispose-date-{{ $component['component_id'] }}" type="date" name="disposed_at"
                                                                value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required>
                                                        </div>
                                                    </div>
                                                    <label class="form-label" for="cdispose-reason-{{ $component['component_id'] }}">Alasan</label>
                                                    <input id="cdispose-reason-{{ $component['component_id'] }}" type="text" name="reason" maxlength="255"
                                                        placeholder="mis. basi, rusak" class="form-control" required>
                                                    <button type="submit" class="btn-danger">Catat Waste</button>
                                                </form>
                                            </details>
                                        @else
                                            <span class="text-xs text-slate-500">Habis</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($bd['editable'])
                    <div class="sales-note-panel">
                        <details>
                            <summary class="cursor-pointer font-semibold text-slate-700">Ubah rincian</summary>
                            @include('salesapp.partials.leftover-breakdown-form', [
                                'action' => route('salesapp.leftovers.breakdowns.update', $bd['breakdown']),
                                'method' => 'PUT',
                                'key' => 'edit-' . $bd['breakdown']->id,
                                'unitCost' => $bd['unit_hpp'],
                                'portionQty' => (float) $bd['breakdown']->portion_qty,
                                'maxPortion' => null,
                                'components' => $bd['breakdown']->components
                                    ->map(fn ($c) => ['name' => $c->name, 'qty' => (float) $c->qty, 'unit' => $c->unit, 'value' => (float) $c->value])
                                    ->all(),
                                'withDate' => false,
                                'notes' => $bd['breakdown']->notes,
                            ])
                        </details>
                        <form method="POST" action="{{ route('salesapp.leftovers.breakdowns.destroy', $bd['breakdown']) }}" class="mt-3"
                            onsubmit="return confirm('Batalkan rincian ini? Porsinya kembali ke stok Barang Sisa.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm font-semibold text-rose-600 hover:underline">Batalkan rincian</button>
                        </form>
                    </div>
                @endif
            </section>
        @endforeach

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
                                    @if ($disposal->component)
                                        {{ $disposal->component->name }} ({{ $disposal->component->unit }}, rincian {{ $disposal->sourceItem?->item_name }})
                                    @else
                                        {{ $disposal->sourceItem?->item_name }}
                                    @endif
                                    <span class="block text-xs font-normal text-slate-500">
                                        retur {{ $disposal->sourceItem?->salesActual?->customer?->name ?? '-' }}
                                    </span>
                                </td>
                                <td class="number-cell text-rose-600" data-label="Qty">{{ $qty($disposal->qty) }}</td>
                                <td class="number-cell" data-label="Nilai (HPP)">
                                    {{ $rupiah($disposal->value()) }}
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

    <script>
        // Rincian Barang Sisa: tampilkan total nilai komponen terhadap HPP porsi yang dirinci.
        document.querySelectorAll('.js-breakdown-form').forEach((form) => {
            const unitCost = Number(form.dataset.unitCost || 0);
            const portion = form.querySelector('.js-bd-portion');
            const total = form.querySelector('.js-bd-total');
            const hpp = form.querySelector('.js-bd-hpp');
            const over = form.querySelector('.js-bd-over');
            const money = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });

            const update = () => {
                const sum = Array.from(form.querySelectorAll('.js-bd-value'))
                    .reduce((carry, input) => carry + Math.max(0, Number(input.value || 0)), 0);
                const limit = Math.max(0, Number(portion?.value || 0)) * unitCost;

                total.textContent = `Rp ${money.format(sum)}`;
                hpp.textContent = `Rp ${money.format(limit)}`;
                over.classList.toggle('hidden', sum - limit <= 0.005);
            };

            form.addEventListener('input', update);
            update();
        });
    </script>
@endsection
