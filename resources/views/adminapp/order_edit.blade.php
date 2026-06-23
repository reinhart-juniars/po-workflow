@extends('layouts.adminapp', ['title' => 'Edit PO'])

@section('content')
<div class="page-toolbar">
    <div>
        <h1 class="section-title">Edit PO - {{ $po->po_number }}</h1>
        <p class="section-subtitle">Perbarui informasi customer, jadwal kirim, dan item purchase order.</p>
    </div>
    <a href="{{ route('adminapp.orders.show', $po->id) }}" class="btn-ghost">Kembali ke Detail</a>
</div>

@if(session('success'))
    <div class="flash-success mt-4">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="flash-error mt-4">
        <b>Terjadi kesalahan:</b>
        <ul class="mt-1 list-disc pl-5">
            @foreach ($errors->all() as $e)
                <li>{{ $e }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form action="{{ route('adminapp.orders.update', $po->id) }}" method="POST" class="mt-4 space-y-5">
    @csrf
    @method('PUT')

    @php
        $deliveryDateValue = old('delivery_date', \Carbon\Carbon::parse($po->delivery_date)->format('Y-m-d'));
        $deliveryTimeOld = old('delivery_time');
        $deliveryTimeValue = $deliveryTimeOld !== null
            ? $deliveryTimeOld
            : ($po->delivery_time ? \Carbon\Carbon::parse($po->delivery_time)->format('H:i') : '');
        $paymentTypeValue = old('payment_type', $po->payment_type ?? 'cash');
        $cashAccountValue = old('cash_account_id', $po->cash_account_id);
        $isCompletedOrder = $po->status === 'completed';
    @endphp

    <section class="form-shell space-y-5">
        @if($isCompletedOrder)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                PO ini sudah berstatus {{ \App\Support\UiLabel::purchaseOrderStatus('completed') }}.
                Gunakan form ini untuk perubahan menu/jumlah akibat pembatalan atau perubahan mendadak dari customer.
                Perubahan total akan otomatis tersinkron ke accounting.
            </div>
        @endif

        <div class="form-grid">
            <div>
                <label class="mb-1.5 block">Customer</label>
                <select name="customer_id" required>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(old('customer_id', $po->customer_id) == $customer->id)>
                            {{ $customer->name }}
                            @if($customer->phone)
                                - {{ $customer->phone }}
                            @endif
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1.5 block">Penerima</label>
                <input type="text" name="recipient_name" value="{{ old('recipient_name', $po->recipient_name) }}" required>
            </div>

            <div class="md:col-span-2">
                <label class="mb-1.5 block">Alamat Kirim</label>
                <textarea name="shipping_address" rows="2" required>{{ old('shipping_address', $po->shipping_address) }}</textarea>
            </div>

            <div>
                <label class="mb-1.5 block">Area</label>
                <select name="area_id" required>
                    @foreach($areas as $id => $name)
                        <option value="{{ $id }}" @selected(old('area_id', $po->area_id) == $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1.5 block">Tanggal Kirim</label>
                <input type="date" name="delivery_date" value="{{ $deliveryDateValue }}" required>
            </div>

            <div>
                <label class="mb-1.5 block">Jam Terima</label>
                <input type="time" name="delivery_time" value="{{ $deliveryTimeValue }}">
            </div>

            <div>
                <label class="mb-1.5 block">Diskon (Rp)</label>
                <input type="number" name="discount_amount" value="{{ old('discount_amount', $po->discount_amount) }}" min="0">
            </div>

            <div>
                <label class="mb-1.5 block">Ongkos Kirim (Rp)</label>
                <input type="number" name="shipping_cost" value="{{ old('shipping_cost', $po->shipping_cost ?? 0) }}" min="0">
            </div>

            <div class="md:col-span-2">
                <label class="mb-1.5 block">Jenis Pembayaran</label>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <label class="cursor-pointer">
                        <input type="radio" name="payment_type" value="cash" class="peer sr-only" @checked($paymentTypeValue === 'cash')>
                        <span class="block rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-700">
                            Tunai
                        </span>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="payment_type" value="receivable" class="peer sr-only" @checked($paymentTypeValue === 'receivable')>
                        <span class="block rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-700">
                            Piutang
                        </span>
                    </label>
                </div>
            </div>

            <div id="cashAccountWrap" class="{{ $paymentTypeValue === 'cash' ? '' : 'hidden' }}">
                <label class="mb-1.5 block">Akun Kas</label>
                <select id="cashAccountInput" name="cash_account_id">
                    <option value="">-- Pilih Akun Kas --</option>
                    @foreach($cashAccounts as $cashAccount)
                        <option value="{{ $cashAccount->id }}" @selected($cashAccountValue == $cashAccount->id)>
                            {{ $cashAccount->name }} ({{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div id="receivableDaysWrap" class="{{ $paymentTypeValue === 'receivable' ? '' : 'hidden' }}">
                <label class="mb-1.5 block">Tempo Piutang (Hari)</label>
                <input type="number"
                       id="receivableDaysInput"
                       name="receivable_days"
                       value="{{ old('receivable_days', $po->receivable_days) }}"
                       min="1"
                       max="365"
                       placeholder="Contoh: 14">
                <p class="mt-1 text-xs text-slate-500">Dipakai untuk reminder accounting sebelum jatuh tempo.</p>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900">Item PO</h2>
                <button type="button" id="btnAddItem" class="btn-primary py-2 text-xs">+ Tambah Item</button>
            </div>

            <div id="itemsPanel" class="space-y-3">
                @php $idx = 0; @endphp
                @foreach ($po->items as $item)
                    <div class="item-row grid grid-cols-1 gap-2 rounded-xl border border-slate-200 bg-white p-3 md:grid-cols-12" data-index="{{ $idx }}">
                        <div class="md:col-span-5">
                            <label class="mb-1 block text-xs text-slate-500">Produk</label>
                            <select name="items[{{ $idx }}][product_id]" class="product-select" required>
                                @foreach ($products as $p)
                                    <option value="{{ $p->id }}" data-price="{{ $p->base_price }}" @selected(old("items.$idx.product_id", $item->product_id) == $p->id)>
                                        {{ $p->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs text-slate-500">Qty</label>
                            <input type="number" name="items[{{ $idx }}][qty]" min="1" value="{{ old("items.$idx.qty", $item->qty) }}" class="qty-input" required>
                        </div>

                        <div class="md:col-span-3">
                            <label class="mb-1 block text-xs text-slate-500">Keterangan</label>
                            <input type="text" name="items[{{ $idx }}][notes]" value="{{ old("items.$idx.notes", $item->notes) }}" class="notes-input" placeholder="contoh: tanpa sambal">
                        </div>

                        <div class="md:col-span-2 flex items-end justify-between gap-2 md:flex-col md:items-end md:justify-between">
                            <div>
                                <div class="text-xs text-slate-500">Subtotal</div>
                                <div class="item-subtotal font-mono text-sm font-semibold text-slate-800">
                                    Rp {{ number_format($item->subtotal, 0, ',', '.') }}
                                </div>
                            </div>
                            <button type="button" class="remove-row text-xs font-semibold text-rose-600 hover:text-rose-500">Hapus</button>
                        </div>
                    </div>
                    @php $idx++; @endphp
                @endforeach
            </div>

            <div class="mt-3 flex justify-end border-t border-slate-200 pt-3">
                <div class="text-right">
                    <div class="text-xs text-slate-500">Total Item</div>
                    <div class="text-lg font-bold text-slate-900" id="itemsTotalDisplay">
                        Rp {{ number_format($po->items->sum('subtotal'), 0, ',', '.') }}
                    </div>
                </div>
            </div>
        </div>

        <div>
            <label class="mb-1.5 block font-medium text-slate-800">Keterangan Perubahan</label>
            <textarea
                name="edit_reason"
                rows="3"
                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800"
                placeholder="Wajib diisi. Contoh: Customer membatalkan 2 porsi setelah barang terkirim."
                required
            >{{ old('edit_reason') }}</textarea>
            <p class="mt-1 text-xs text-slate-500">
                Keterangan ini disimpan sebagai jejak audit perubahan PO, terutama setelah status completed.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="submit" class="btn-primary">Simpan Perubahan</button>
            <a href="{{ route('adminapp.orders.show', $po->id) }}" class="btn-ghost">Batal</a>
        </div>
    </section>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const itemsPanel = document.getElementById('itemsPanel');
    const btnAddItem = document.getElementById('btnAddItem');
    const paymentTypeRadios = document.querySelectorAll('input[name="payment_type"]');
    const receivableDaysWrap = document.getElementById('receivableDaysWrap');
    const receivableDaysInput = document.getElementById('receivableDaysInput');
    const cashAccountWrap = document.getElementById('cashAccountWrap');
    const cashAccountInput = document.getElementById('cashAccountInput');
    let idx = {{ $po->items->count() }};

    if (!itemsPanel) {
        return;
    }

    function syncReceivableDaysVisibility() {
        const selected = document.querySelector('input[name="payment_type"]:checked');
        const isPiutang = selected && selected.value === 'receivable';
        const isCash = selected && selected.value === 'cash';

        if (receivableDaysWrap) {
            receivableDaysWrap.classList.toggle('hidden', !isPiutang);
        }

        if (cashAccountWrap) {
            cashAccountWrap.classList.toggle('hidden', !isCash);
        }

        if (!receivableDaysInput) return;

        if (isPiutang) {
            receivableDaysInput.setAttribute('required', 'required');
        } else {
            receivableDaysInput.removeAttribute('required');
            receivableDaysInput.value = '';
        }

        if (!cashAccountInput) return;

        if (isCash) {
            cashAccountInput.setAttribute('required', 'required');
            return;
        }

        cashAccountInput.removeAttribute('required');
        cashAccountInput.value = '';
    }

    paymentTypeRadios.forEach(radio => {
        radio.addEventListener('change', syncReceivableDaysVisibility);
    });

    syncReceivableDaysVisibility();

    function formatRupiah(num) {
        return num.toLocaleString('id-ID');
    }

    function recalcTotal() {
        let grand = 0;

        itemsPanel.querySelectorAll('.item-row').forEach(row => {
            const select = row.querySelector('.product-select');
            const qtyInput = row.querySelector('.qty-input');
            const subtotalEl = row.querySelector('.item-subtotal');

            const price = select && select.selectedOptions[0]
                ? parseFloat(select.selectedOptions[0].dataset.price || 0)
                : 0;
            const qty = qtyInput ? parseFloat(qtyInput.value || 0) : 0;
            const sub = price * qty;

            grand += sub;

            if (subtotalEl) {
                subtotalEl.textContent = 'Rp ' + formatRupiah(sub);
            }
        });

        const totalEl = document.getElementById('itemsTotalDisplay');
        if (totalEl) {
            totalEl.textContent = 'Rp ' + formatRupiah(grand);
        }
    }

    function bindRowEvents(row) {
        const select = row.querySelector('.product-select');
        const qtyInput = row.querySelector('.qty-input');
        const remove = row.querySelector('.remove-row');

        if (select) select.addEventListener('change', recalcTotal);
        if (qtyInput) qtyInput.addEventListener('input', recalcTotal);
        if (remove) {
            remove.addEventListener('click', () => {
                row.remove();
                recalcTotal();
            });
        }
    }

    itemsPanel.querySelectorAll('.item-row').forEach(bindRowEvents);

    if (btnAddItem) {
        btnAddItem.addEventListener('click', () => {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = `
            <div class="item-row grid grid-cols-1 gap-2 rounded-xl border border-slate-200 bg-white p-3 md:grid-cols-12" data-index="${idx}">
                <div class="md:col-span-5">
                    <label class="mb-1 block text-xs text-slate-500">Produk</label>
                    <select name="items[${idx}][product_id]" class="product-select" required>
                        <option value="">-- Pilih Menu --</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->id }}" data-price="{{ $p->base_price }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs text-slate-500">Qty</label>
                    <input type="number" name="items[${idx}][qty]" min="1" class="qty-input" placeholder="Qty" required>
                </div>
                <div class="md:col-span-3">
                    <label class="mb-1 block text-xs text-slate-500">Keterangan</label>
                    <input type="text" name="items[${idx}][notes]" class="notes-input" placeholder="contoh: tanpa sambal">
                </div>
                <div class="md:col-span-2 flex items-end justify-between gap-2 md:flex-col md:items-end md:justify-between">
                    <div>
                        <div class="text-xs text-slate-500">Subtotal</div>
                        <div class="item-subtotal font-mono text-sm font-semibold text-slate-800">Rp 0</div>
                    </div>
                    <button type="button" class="remove-row text-xs font-semibold text-rose-600 hover:text-rose-500">Hapus</button>
                </div>
            </div>`;
            const row = wrapper.firstElementChild;
            itemsPanel.appendChild(row);
            bindRowEvents(row);
            idx++;
            recalcTotal();
        });
    }

    recalcTotal();
});
</script>
@endsection
