@extends('layouts.salesapp', ['title' => 'Edit Sales Actual'])

@section('content')
    @php
        $isDraft = $salesActual->status === 'draft';
        $totalActual = $salesActual->items->sum('subtotal_actual');
        $totalReturn = $salesActual->items->sum('qty_return');
        $totalWaste = $salesActual->items->sum('qty_waste');
    @endphp

  <div class="sales-page">
    <section class="dashboard-hero sales-hero">
        <div class="page-toolbar">
            <div class="min-w-0">
                <h1 class="dashboard-hero-title">Sales Actual {{ $salesActual->sales_date?->format('d M Y') }}</h1>
                <p class="dashboard-hero-subtitle">
                    Isi qty actual sesuai barang yang terjual. Sisa dari qty delivery otomatis menjadi retur.
                    {{ $salesActual->customer->name ?? '-' }} | {{ $salesActual->deliveryOrder->do_code ?? 'Tanpa DO' }}
                </p>
            </div>
            <a href="{{ route('salesapp.dashboard') }}" class="btn-ghost">Kembali</a>
        </div>
    </section>

    <section class="sales-stats-grid-narrow">
        <article class="sales-stat-card">
            <p class="stat-label">Status</p>
            <p class="sales-stat-value {{ $isDraft ? 'text-amber-600' : 'text-emerald-700' }}">{{ ucfirst($salesActual->status) }}
            </p>
            <p class="stat-meta">
                {{ $salesActual->submitted_at ? 'Submitted ' . $salesActual->submitted_at->format('d M Y H:i') : 'Belum final' }}
            </p>
        </article>
        <article class="sales-stat-card">
            <p class="stat-label">Total Actual</p>
            <p class="sales-stat-value-money text-brand-600">Rp {{ number_format((float) $totalActual, 0, ',', '.') }}</p>
            <p class="stat-meta">Nilai jual final dari qty actual</p>
        </article>
        <article class="sales-stat-card">
            <p class="stat-label">Total Retur</p>
            <p class="sales-stat-value text-rose-600">{{ number_format((float) $totalReturn, 2, ',', '.') }}</p>
            <p class="stat-meta">Masuk Barang Sisa setelah submit</p>
        </article>
        <article class="sales-stat-card">
            <p class="stat-label">Total Waste</p>
            <p class="sales-stat-value text-rose-600">{{ number_format((float) $totalWaste, 2, ',', '.') }}</p>
            <p class="stat-meta">Barang Sisa yang dibuang (tidak layak jual)</p>
        </article>
    </section>

    <form method="POST" action="{{ route('salesapp.actuals.update', $salesActual) }}" class="table-shell sales-table sales-responsive-table">
        @csrf
        @method('PUT')

        <div class="table-head flex items-center justify-between gap-3">
            <span>Item Actual</span>
            @unless ($isDraft)
                <span class="badge-soft-emerald">Readonly</span>
            @endunless
        </div>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty Delivery</th>
                        <th>Qty Actual</th>
                        <th>Qty Waste</th>
                        <th>Qty Retur</th>
                        <th>Harga</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($salesActual->items as $item)
                        @php
                            $displayQtyActual = (float) old('items.' . $item->id . '.qty_actual', $item->qty_actual);
                            $displayQtyWaste = $item->is_carry_forward
                                ? (float) old('items.' . $item->id . '.qty_waste', $item->qty_waste)
                                : 0.0;
                            $displayQtyReturn = max(0, round((float) $item->qty_delivery - $displayQtyActual - $displayQtyWaste, 2));
                            $displayUnitPrice = (float) old('items.' . $item->id . '.unit_price', $item->unit_price);
                            $displayProductId = old('items.' . $item->id . '.product_id', $item->product_id);
                            $displaySubtotal = round($displayQtyActual * $displayUnitPrice, 2);
                        @endphp
                        <tr>
                            <td class="item-cell" data-label="Item">
                                @if ($isDraft && $item->is_carry_forward && ! $item->leftover_component_id)
                                    <select
                                        name="items[{{ $item->id }}][product_id]"
                                        class="form-control min-w-52 js-carry-product"
                                        data-price-target="unit-price-{{ $item->id }}"
                                        data-unit-target="item-unit-{{ $item->id }}"
                                    >
                                        @foreach ($products as $product)
                                            <option
                                                value="{{ $product->id }}"
                                                data-price="{{ (float) $product->base_price }}"
                                                data-unit="{{ $product->unit }}"
                                                @selected((string) $displayProductId === (string) $product->id)
                                            >
                                                {{ $product->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="mt-1 text-xs text-slate-500">
                                        Unit: <span id="item-unit-{{ $item->id }}">{{ $item->unit ?: '-' }}</span>
                                        | @include('salesapp.partials.leftover-origin', ['item' => $item])
                                    </div>
                                    <button type="submit" form="remove-leftover-{{ $item->id }}" class="mt-1 text-xs font-semibold text-rose-600 hover:underline">
                                        Lepas dari Sales Actual ini
                                    </button>
                                @else
                                    <div class="font-semibold text-slate-900">{{ $item->item_name }}</div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ $item->unit ?: '-' }}
                                        @if ($item->is_carry_forward)
                                            | @include('salesapp.partials.leftover-origin', ['item' => $item])
                                        @elseif ($item->is_extra_portion)
                                            | <span class="font-semibold text-brand-600">Porsi Tambahan</span>, harga {{ $item->purchaseOrder?->po_number ?? 'PO' }}
                                        @elseif ($item->purchaseOrderItem?->purchaseOrder)
                                            | {{ $item->purchaseOrderItem->purchaseOrder->po_number }}
                                        @endif
                                    </div>
                                    @if ($isDraft && $item->is_carry_forward)
                                        <button type="submit" form="remove-leftover-{{ $item->id }}" class="mt-1 text-xs font-semibold text-rose-600 hover:underline">
                                            Lepas dari Sales Actual ini
                                        </button>
                                    @elseif ($isDraft && $item->is_extra_portion)
                                        <button type="submit" form="remove-extra-{{ $item->id }}" class="mt-1 text-xs font-semibold text-rose-600 hover:underline">
                                            Hapus Porsi Tambahan
                                        </button>
                                    @endif
                                @endif
                            </td>
                            <td class="number-cell" data-label="Qty Delivery">{{ number_format((float) $item->qty_delivery, 2, ',', '.') }}</td>
                            <td data-label="Qty Actual">
                                <input type="number" step="0.01" min="0"
                                    name="items[{{ $item->id }}][qty_actual]" value="{{ $displayQtyActual }}"
                                    class="form-control min-w-28 text-right tabular-nums js-qty-actual"
                                    data-delivery="{{ (float) $item->qty_delivery }}"
                                    data-price="{{ $displayUnitPrice }}"
                                    data-price-input="unit-price-{{ $item->id }}"
                                    data-return-target="qty-return-{{ $item->id }}"
                                    data-subtotal-target="subtotal-actual-{{ $item->id }}"
                                    @if ($item->is_carry_forward) data-waste-input="qty-waste-{{ $item->id }}" @endif
                                    @disabled(!$isDraft)>
                            </td>
                            <td data-label="Qty Waste">
                                @if ($item->is_carry_forward)
                                    <input id="qty-waste-{{ $item->id }}" type="number" step="0.01" min="0"
                                        name="items[{{ $item->id }}][qty_waste]" value="{{ $displayQtyWaste }}"
                                        class="form-control min-w-28 text-right tabular-nums js-qty-waste"
                                        data-delivery="{{ (float) $item->qty_delivery }}"
                                        @disabled(!$isDraft)>
                                    <p class="mt-1 text-[11px] text-slate-500">Barang tidak layak jual</p>
                                @else
                                    <span class="number-cell block text-slate-400">-</span>
                                @endif
                            </td>
                            <td class="number-cell" data-label="Qty Retur">
                                <span id="qty-return-{{ $item->id }}" class="font-semibold text-rose-600">
                                    {{ number_format($displayQtyReturn, 2, ',', '.') }}
                                </span>
                                {{-- <p class="mt-1 text-[11px] text-slate-500">Qty delivery - qty actual</p> --}}
                            </td>
                            <td class="number-cell" data-label="Harga">
                                @if ($isDraft && $item->is_carry_forward)
                                    <input
                                        id="unit-price-{{ $item->id }}"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        name="items[{{ $item->id }}][unit_price]"
                                        value="{{ $displayUnitPrice }}"
                                        class="form-control min-w-32 text-right tabular-nums js-unit-price"
                                    >
                                @else
                                    Rp {{ number_format((float) $item->unit_price, 0, ',', '.') }}
                                @endif
                            </td>
                            <td id="subtotal-actual-{{ $item->id }}" class="number-cell font-semibold" data-label="Subtotal">
                                Rp {{ number_format($displaySubtotal, 0, ',', '.') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="sales-note-panel">
            <label class="form-label" for="sales-actual-notes">
                Catatan <span id="notes-required-label" class="hidden text-rose-600">*</span>
            </label>
            <textarea id="sales-actual-notes" name="notes" rows="3" class="form-control"
                placeholder="Catatan wajib diisi jika ada retur." @disabled(!$isDraft) @readonly(!$isDraft)>{{ old('notes', $salesActual->notes) }}</textarea>
            <p id="notes-required-help" class="mt-1 hidden text-xs font-medium text-rose-600">
                Catatan wajib diisi karena ada retur.
            </p>
            @unless ($isDraft)
                <p class="mt-1 text-xs font-medium text-slate-500">
                    Sales actual sudah submitted dan tidak bisa diubah lagi.
                </p>
            @endunless

            @if ($isDraft)
                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <button type="submit" class="btn-primary">Simpan Perubahan</button>
                </div>
            @endif
        </div>
    </form>

    @if ($isDraft)
        {{-- Form lepas Penjualan Barang Sisa ada di luar form utama (form tidak boleh bersarang);
             tombolnya di baris tabel menunjuk ke sini lewat atribut form. --}}
        @foreach ($salesActual->items->where('is_carry_forward', true) as $item)
            <form id="remove-leftover-{{ $item->id }}" method="POST" class="hidden"
                action="{{ route('salesapp.actuals.leftovers.destroy', [$salesActual, $item]) }}"
                onsubmit="return confirm('Lepas {{ $item->item_name }} dari Sales Actual ini? Qty-nya kembali ke stok Barang Sisa.')">
                @csrf
                @method('DELETE')
            </form>
        @endforeach
        @foreach ($salesActual->items->where('is_extra_portion', true) as $item)
            <form id="remove-extra-{{ $item->id }}" method="POST" class="hidden"
                action="{{ route('salesapp.actuals.extra-portions.destroy', [$salesActual, $item]) }}"
                onsubmit="return confirm('Hapus Porsi Tambahan {{ $item->item_name }}?')">
                @csrf
                @method('DELETE')
            </form>
        @endforeach

        <form method="POST" action="{{ route('salesapp.actuals.extra-portions.store', $salesActual) }}" class="section-card">
            @csrf
            <h2 class="panel-title">Porsi Tambahan</h2>
            <p class="section-subtitle mt-1">
                Customer minta lebih dari yang dikirim, misalnya ganti menu (10 Nasi Goreng + 5 Bakmi menjadi 13 + 2):
                kurangi qty actual menu yang tidak diambil (otomatis jadi Barang Sisa), lalu tambahkan porsi menu penggantinya di sini.
                Harganya mengikuti harga PO. Bila porsinya diambil dari Barang Sisa, pakai Penjualan Barang Sisa di bawah.
            </p>

            @if ($extraPortionLines->isEmpty())
                <p class="mt-3 text-sm text-slate-500">Sales Actual ini tidak punya menu dari PO.</p>
            @else
                <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem_auto] sm:items-end">
                    <div>
                        <label class="form-label" for="extra-line">Menu PO</label>
                        <select id="extra-line" name="extra_line_id" class="form-control" required>
                            <option value="">Pilih menu</option>
                            @foreach ($extraPortionLines as $line)
                                <option value="{{ $line->id }}" @selected((string) old('extra_line_id') === (string) $line->id)>
                                    {{ $line->item_name }} - Rp {{ number_format((float) $line->unit_price, 0, ',', '.') }}
                                    ({{ $line->purchaseOrderItem?->purchaseOrder?->po_number ?? 'PO' }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="extra-qty">Qty</label>
                        <input id="extra-qty" type="number" name="extra_qty" step="0.01" min="0.01"
                            value="{{ old('extra_qty') }}" class="form-control text-right tabular-nums" required>
                    </div>
                    <button type="submit" class="btn-primary">Tambahkan</button>
                </div>
            @endif
        </form>

        <form method="POST" action="{{ route('salesapp.actuals.leftovers.store', $salesActual) }}" class="section-card">
            @csrf
            <h2 class="panel-title">Penjualan Barang Sisa</h2>
            <p class="section-subtitle mt-1">
                Jual retur yang masih tersedia ke customer ini. Cara bayarnya mengikuti PO customer ini di DO.
            </p>

            @if ($leftoverStock->isEmpty() && $leftoverComponents->isEmpty())
                <p class="mt-3 text-sm text-slate-500">Tidak ada Barang Sisa yang tersedia.</p>
            @else
                <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem_10rem_auto] sm:items-end">
                    <div>
                        <label class="form-label" for="leftover-entry">Barang Sisa</label>
                        <select id="leftover-entry" name="leftover_source" class="form-control js-leftover-entry" required>
                            <option value="">Pilih Barang Sisa</option>
                            @if ($leftoverStock->isNotEmpty())
                                <optgroup label="Porsi utuh">
                                    @foreach ($leftoverStock as $row)
                                        <option value="entry:{{ $row['entry_id'] }}"
                                            data-available="{{ $row['available_qty'] }}"
                                            data-price="{{ $row['unit_price'] }}"
                                            @selected(old('leftover_source') === 'entry:' . $row['entry_id'])>
                                            {{ $row['item_name'] }} - sisa {{ number_format($row['available_qty'], 2, ',', '.') }}
                                            (retur {{ $row['customer_name'] ?? '-' }}, {{ \Illuminate\Support\Carbon::parse($row['returned_at'])->format('d M') }})
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if ($leftoverComponents->isNotEmpty())
                                <optgroup label="Komponen rincian (harga diisi sendiri)">
                                    @foreach ($leftoverComponents as $row)
                                        <option value="component:{{ $row['component_id'] }}"
                                            data-available="{{ $row['available_qty'] }}"
                                            data-price=""
                                            @selected(old('leftover_source') === 'component:' . $row['component_id'])>
                                            {{ $row['name'] }} - sisa {{ number_format($row['available_qty'], 2, ',', '.') }} {{ $row['unit'] }}
                                            (rincian {{ $row['origin_name'] }}, retur {{ $row['customer_name'] ?? '-' }})
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="leftover-qty">Qty</label>
                        <input id="leftover-qty" type="number" name="leftover_qty" step="0.01" min="0.01"
                            value="{{ old('leftover_qty') }}" class="form-control text-right tabular-nums" required>
                    </div>
                    <div>
                        <label class="form-label" for="leftover-price">Harga</label>
                        <input id="leftover-price" type="number" name="leftover_price" step="0.01" min="0"
                            value="{{ old('leftover_price') }}" class="form-control text-right tabular-nums">
                    </div>
                    <button type="submit" class="btn-primary">Tambahkan</button>
                </div>
            @endif
        </form>

        <form method="POST" action="{{ route('salesapp.actuals.submit', $salesActual) }}" class="section-card">
            @csrf
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="panel-title">Submit Final</h2>
                    <p class="section-subtitle mt-1">Kunci hasil penjualan ini. Nilai actual masuk ke Accounting dan retur masuk Barang Sisa.</p>
                </div>
                <button type="submit" class="btn-success"
                    onclick="return confirm('Submit sales actual ini sebagai penjualan final? Setelah submit, data tidak bisa diedit lagi.')">
                    Submit Sales Actual
                </button>
            </div>
        </form>
    @endif
  </div>

    <script>
        const notesInput = document.getElementById('sales-actual-notes');
        const notesRequiredLabel = document.getElementById('notes-required-label');
        const notesRequiredHelp = document.getElementById('notes-required-help');

        const updateNotesRequirement = () => {
            const hasReturn = Array.from(document.querySelectorAll('.js-qty-actual')).some((actualInput) => {
                const actualQty = Math.max(0, Number(actualInput.value || 0));
                const deliveryQty = Number(actualInput.dataset.delivery || 0);
                const wasteInput = actualInput.dataset.wasteInput
                    ? document.getElementById(actualInput.dataset.wasteInput)
                    : null;
                const wasteQty = wasteInput ? Math.max(0, Number(wasteInput.value || 0)) : 0;

                return deliveryQty - actualQty - wasteQty > 0.00001;
            });

            if (notesInput && !notesInput.disabled) {
                notesInput.required = hasReturn;
            }

            notesRequiredLabel?.classList.toggle('hidden', !hasReturn);
            notesRequiredHelp?.classList.toggle('hidden', !hasReturn);
        };

        document.querySelectorAll('.js-qty-actual').forEach((input) => {
            const returnTarget = document.getElementById(input.dataset.returnTarget);
            const subtotalTarget = document.getElementById(input.dataset.subtotalTarget);
            const priceInput = input.dataset.priceInput ? document.getElementById(input.dataset.priceInput) : null;
            const wasteInput = input.dataset.wasteInput ? document.getElementById(input.dataset.wasteInput) : null;
            const deliveryQty = Number(input.dataset.delivery || 0);
            const qtyFormatter = new Intl.NumberFormat('id-ID', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
            const moneyFormatter = new Intl.NumberFormat('id-ID', {
                maximumFractionDigits: 0,
            });

            const updateCalculatedValues = () => {
                const actualQty = Math.max(0, Number(input.value || 0));
                const wasteQty = wasteInput ? Math.max(0, Number(wasteInput.value || 0)) : 0;
                const unitPrice = priceInput ? Math.max(0, Number(priceInput.value || 0)) : Number(input.dataset.price || 0);
                const returnQty = Math.max(0, deliveryQty - actualQty - wasteQty);
                const subtotal = actualQty * unitPrice;

                if (returnTarget) {
                    returnTarget.textContent = qtyFormatter.format(returnQty);
                }

                if (subtotalTarget) {
                    subtotalTarget.textContent = `Rp ${moneyFormatter.format(subtotal)}`;
                }

                updateNotesRequirement();
            };

            input.addEventListener('input', updateCalculatedValues);
            priceInput?.addEventListener('input', updateCalculatedValues);
            wasteInput?.addEventListener('input', updateCalculatedValues);
            updateCalculatedValues();
        });

        // Pilih Barang Sisa: isi qty (maksimal sisa) dan harga jual asalnya.
        document.querySelectorAll('.js-leftover-entry').forEach((select) => {
            const qtyInput = document.getElementById('leftover-qty');
            const priceInput = document.getElementById('leftover-price');

            select.addEventListener('change', () => {
                const selected = select.selectedOptions[0];

                if (!selected || !selected.value) {
                    return;
                }

                if (qtyInput) {
                    qtyInput.max = selected.dataset.available || '';
                    qtyInput.value = selected.dataset.available || '';
                }

                if (priceInput) {
                    priceInput.value = selected.dataset.price || '';
                }
            });
        });

        document.querySelectorAll('.js-carry-product').forEach((select) => {
            const priceTarget = select.dataset.priceTarget ? document.getElementById(select.dataset.priceTarget) : null;
            const unitTarget = select.dataset.unitTarget ? document.getElementById(select.dataset.unitTarget) : null;

            select.addEventListener('change', () => {
                const selected = select.selectedOptions[0];

                if (priceTarget && selected) {
                    priceTarget.value = selected.dataset.price || 0;
                    priceTarget.dispatchEvent(new Event('input', { bubbles: true }));
                }

                if (unitTarget && selected) {
                    unitTarget.textContent = selected.dataset.unit || '-';
                }
            });
        });
    </script>
@endsection
