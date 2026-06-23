@extends('layouts.adminapp', ['title' => 'Orders'])

@section('content')
<div class="page-toolbar">
    <div>
      <h1 class="section-title">Orders - PO Hari Ini</h1>
      <p class="section-subtitle">Buat draft purchase order baru dan kelola item pesanan.</p>
    </div>
</div>

  {{-- Create New Order --}}
  <div id="createPoPanel" class="form-shell mt-4">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-semibold">Buat PO Baru (Draft)</h2>
      <div class="text-sm text-gray-500">
          Nomor PO (preview):
          <span class="font-mono">{{ $previewPo }}</span>
      </div>
    </div>

    @if ($errors->any())
      <div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-700 text-sm">
        <b>Terjadi kesalahan:</b>
        <ul class="list-disc pl-4">
          @foreach ($errors->all() as $e)
            <li>{{ $e }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form action="{{ route('adminapp.orders.store') }}" method="POST" class="space-y-5">
      @csrf
      @php
        $paymentType = old('payment_type', 'cash');
        $cashAccountId = old('cash_account_id');
      @endphp
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- CUSTOMER --}}
      <div>
          <label class="block text-sm text-gray-600 mb-1">Customer</label>
          <div class="flex items-center gap-2">
            <select id="customerSelect"
                    name="customer_id"
                    class="flex-1 border rounded px-3 py-2"
                    required>
              <option value="">-- Pilih Customer --</option>
              @foreach($customers as $customer)
                <option value="{{ $customer->id }}"
                        data-name="{{ $customer->name }}"
                        data-address="{{ $customer->address }}"
                        data-phone="{{ $customer->phone }}"
                        data-area-id="{{ $customer->area_id }}"
                        {{ old('customer_id') == $customer->id ? 'selected' : '' }}>
                    {{ $customer->name }}
                    @if($customer->phone)
                        - {{ $customer->phone }}
                    @endif
                </option>
              @endforeach
            </select>

            <button type="button"
                    id="btnUseCustomerData"
                    class="px-3 py-2 text-xs border rounded bg-gray-100 hover:bg-gray-200">
              Pakai data customer
            </button>
          </div>

          <a href="{{ route('adminapp.customers.create') }}"
            class="text-xs text-indigo-600 hover:underline">
            + Tambah Customer Baru
          </a>
        </div>

        {{-- PENERIMA --}}
        <div>
          <label class="block text-sm text-gray-600 mb-1">Penerima</label>
          <input type="text" name="recipient_name" id="recipientInput"
                value="{{ old('recipient_name') }}"
                class="w-full border rounded px-3 py-2 text-sm" required>
        </div>

        {{-- Alamat --}}
        <div class="md:col-span-2">
          <label class="block text-sm text-gray-600 mb-1">Alamat Kirim</label>
          <textarea id="shippingAddress"
                    name="shipping_address"
                    rows="2"
                    class="w-full border rounded px-3 py-2"
                    required>{{ old('shipping_address') }}</textarea>
        </div>

        {{-- Area --}}
        <div>
          <label class="block text-sm text-gray-600 mb-1">Area</label>
          <select id="areaSelect"
                  name="area_id"
                  class="w-full border rounded px-3 py-2"
                  required>
            <option value="">-- Pilih Area --</option>
            @foreach ($areas as $id => $name)
              <option value="{{ $id }}" @selected(old('area_id')==$id)>{{ $name }}</option>
            @endforeach
          </select>
        </div>

        {{-- TANGGAL KIRIM --}}
        <div>
          <label class="block text-sm text-gray-600 mb-1">Tanggal Kirim</label>
          <input type="date" 
                 name="delivery_date"
                 value="{{ old('delivery_date', now()->toDateString()) }}"
                 class="w-full border rounded px-3 py-2 text-sm" required>
            {{-- <p class="text-xs text-gray-500 mt-1">Format tampilan: dd/mm/yyyy (otomatis dari browser).</p> --}}
        </div>

        {{-- JAM TERIMA --}}
        <div>
          <label class="block text-sm text-gray-600 mb-1">Jam Terima</label>
          <input type="time"
                 name="delivery_time"
                 value="{{ old('delivery_time') }}"
                 step="60"
                 class="w-full border rounded px-3 py-2 text-sm">
            <p class="text-xs text-gray-500 mt-1">Format: 24 jam (HH:MM), contoh: 03:00, 14:30, 18:00.</p>
        </div>

        {{-- DISKON --}}
        <div>
          <label class="block text-sm text-gray-600 mb-1">Diskon (Rp)</label>
          <input type="number" name="discount_amount"
                value="{{ old('discount_amount',0) }}"
                min="0"
                class="w-full border rounded px-3 py-2 text-sm">
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- ONGKIR --}}
        <div>
          <label class="block text-sm text-gray-600 mb-1">Ongkos Kirim (Rp)</label>
          <input type="number" name="shipping_cost"
                value="{{ old('shipping_cost', 0) }}"
                min="0"
                class="w-full border rounded px-3 py-2 text-sm">
        </div>

        {{-- JENIS PEMBAYARAN --}}
        <div>
          <label class="block text-sm text-gray-600 mb-1">Jenis Pembayaran</label>
          <div class="grid grid-cols-2 gap-2">
            <label class="cursor-pointer">
              <input type="radio" name="payment_type" value="cash" class="peer sr-only" @checked($paymentType === 'cash')>
              <span class="block rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-700">
                Tunai
              </span>
            </label>
            <label class="cursor-pointer">
              <input type="radio" name="payment_type" value="receivable" class="peer sr-only" @checked($paymentType === 'receivable')>
              <span class="block rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-700">
                Piutang
              </span>
            </label>
          </div>
        </div>

        <div id="cashAccountWrap" class="{{ $paymentType === 'cash' ? '' : 'hidden' }}">
          <label class="block text-sm text-gray-600 mb-1">Akun Kas</label>
          <select name="cash_account_id" id="cashAccountInput" class="w-full border rounded px-3 py-2">
            <option value="">-- Pilih Akun Kas --</option>
            @foreach($cashAccounts as $cashAccount)
              <option value="{{ $cashAccount->id }}" @selected($cashAccountId == $cashAccount->id)>
                {{ $cashAccount->name }} ({{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
              </option>
            @endforeach
          </select>
        </div>

        {{-- HARI PIUTANG --}}
        <div id="receivableDaysWrap" class="{{ $paymentType === 'receivable' ? '' : 'hidden' }}">
          <label class="block text-sm text-gray-600 mb-1">Tempo Piutang (Hari)</label>
          <input type="number"
                name="receivable_days"
                id="receivableDaysInput"
                value="{{ old('receivable_days') }}"
                min="1"
                max="365"
                placeholder="Contoh: 14">
          <p class="text-xs text-gray-500 mt-1">Dipakai untuk reminder accounting sebelum jatuh tempo.</p>
        </div>
      </div>

      {{-- Panel Item --}}
      <div class="border rounded p-4 mt-4">
        <div class="font-semibold mb-3">Item</div>

        {{-- Form kecil untuk tambah 1 item --}}
        <div class="grid grid-cols-12 gap-2 mb-3 items-end">
          <div class="col-span-5">
            <label class="block text-xs text-gray-600 mb-1">Produk</label>
            <select id="itemProduct"
                    class="w-full border rounded px-2 py-2 text-sm">
              <option value="">-- Pilih Produk --</option>
              @foreach ($products as $p)
                <option value="{{ $p->id }}"
                        data-price="{{ $p->base_price }}">
                  {{ $p->name }}
                </option>
              @endforeach
            </select>
          </div>

          <div class="col-span-2">
            <label class="block text-xs text-gray-600 mb-1">Qty</label>
            <input id="itemQty"
                  type="number"
                  min="1"
                  class="w-full border rounded px-2 py-2 text-sm"
                  value=""
                  placeholder="-">
          </div>

          <div class="col-span-3">
            <label class="block text-xs text-gray-600 mb-1">Keterangan</label>
            <input id="itemNotes"
                  type="text"
                  class="w-full border rounded px-2 py-2 text-sm"
                  placeholder="contoh: tanpa sambal">
          </div>

          <div class="col-span-2 text-right">
            <button type="button"
                    id="btnAddItem"
                    class="px-3 py-2 rounded bg-indigo-600 text-white text-xs">
              + Tambah Item
            </button>
          </div>
        </div>

        {{-- Panel daftar item --}}
        <div id="itemsPanel" class="border-t pt-3 mt-3 space-y-2 text-sm">
          <div class="text-gray-500 text-xs" id="noItemsHint">
            Belum ada item. Pilih produk, isi qty, lalu klik "Tambah Item".
          </div>
        </div>

        {{-- Ringkasan total --}}
        <div class="border-t mt-3 pt-3 flex justify-end">
          <div class="text-right">
            <div class="text-xs text-gray-500">Total</div>
            <div class="text-lg font-bold" id="itemsTotalDisplay">Rp 0</div>
          </div>
        </div>
      </div>

      <div>
        <button class="px-4 py-2 rounded bg-green-600 text-white text-sm">
          Simpan (Draft)
        </button>
      </div>
    </form>
  </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // ==========================
    // 1. PAKAI DATA CUSTOMER
    // ==========================
    const customerSelect  = document.getElementById('customerSelect');
    const btnUseCustomer  = document.getElementById('btnUseCustomerData');

    const recipientInput  = document.getElementById('recipientInput');
    const shippingAddress = document.getElementById('shippingAddress');
    const areaSelect      = document.getElementById('areaSelect');
    const paymentTypeRadios = document.querySelectorAll('input[name="payment_type"]');
    const receivableDaysWrap = document.getElementById('receivableDaysWrap');
    const receivableDaysInput = document.getElementById('receivableDaysInput');
    const cashAccountWrap = document.getElementById('cashAccountWrap');
    const cashAccountInput = document.getElementById('cashAccountInput');

    function syncReceivableDaysVisibility() {
        const selected = document.querySelector('input[name="payment_type"]:checked');
        const isPiutang = selected && selected.value === 'receivable';
        const isCash = selected && selected.value === 'cash';

        if (!receivableDaysWrap) return;

        receivableDaysWrap.classList.toggle('hidden', !isPiutang);
        if (cashAccountWrap) {
            cashAccountWrap.classList.toggle('hidden', !isCash);
        }
        if (receivableDaysInput) {
            if (isPiutang) {
                receivableDaysInput.setAttribute('required', 'required');
            } else {
                receivableDaysInput.removeAttribute('required');
                receivableDaysInput.value = '';
            }
        }
        if (cashAccountInput) {
            if (isCash) {
                cashAccountInput.setAttribute('required', 'required');
            } else {
                cashAccountInput.removeAttribute('required');
                cashAccountInput.value = '';
            }
        }
    }

    paymentTypeRadios.forEach(radio => {
        radio.addEventListener('change', syncReceivableDaysVisibility);
    });

    syncReceivableDaysVisibility();

    if (btnUseCustomer && customerSelect) {
        btnUseCustomer.addEventListener('click', () => {
            const opt = customerSelect.options[customerSelect.selectedIndex];
            if (!opt || !opt.value) {
                alert('Pilih customer dulu.');
                return;
            }

            const name   = opt.getAttribute('data-name')      || '';
            const addr   = opt.getAttribute('data-address')   || '';
            const phone  = opt.getAttribute('data-phone')     || '';
            const areaId = opt.getAttribute('data-area-id')   || '';

            if (recipientInput && name) {
                recipientInput.value = name;          // hanya nama
            }
            if (addr)  shippingAddress.value = addr;

            if (areaId) {
                for (let i = 0; i < areaSelect.options.length; i++) {
                    if (areaSelect.options[i].value == areaId) {
                        areaSelect.selectedIndex = i;
                        break;
                    }
                }
            }
        });
    }

    // ==========================
    // 2. PANEL ITEM + TOTAL
    // ==========================
    const itemProduct       = document.getElementById('itemProduct');
    const itemQty           = document.getElementById('itemQty');
    const itemNotes         = document.getElementById('itemNotes');
    const btnAddItem        = document.getElementById('btnAddItem');
    const itemsPanel        = document.getElementById('itemsPanel');
    const noItemsHint       = document.getElementById('noItemsHint');
    const itemsTotalDisplay = document.getElementById('itemsTotalDisplay');

    let itemsIndex = 0;

    function formatRupiah(num) {
        return 'Rp ' + (num || 0).toLocaleString('id-ID');
    }

    function recalcTotal() {
        let grand = 0;
        itemsPanel.querySelectorAll('[data-item-subtotal]').forEach(span => {
            const val = parseFloat(span.getAttribute('data-item-subtotal') || '0');
            if (!isNaN(val)) grand += val;
        });
        if (itemsTotalDisplay) {
            itemsTotalDisplay.textContent = formatRupiah(grand);
        }
    }

    function addItemRow(prodId, prodName, price, qty, notes) {
        if (!itemsPanel) return;

        const subtotal = price * qty;

        if (noItemsHint) {
            noItemsHint.classList.add('hidden');
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'flex items-start justify-between border rounded px-3 py-2 gap-2';

        // Bagian teks / tampilan
        wrapper.innerHTML = `
            <div>
                <div class="font-semibold">${prodName}</div>
                <div class="text-xs text-gray-500">
                    Qty: ${qty} × ${price.toLocaleString('id-ID')} =
                    <span data-item-subtotal="${subtotal}">
                        ${subtotal.toLocaleString('id-ID')}
                    </span>
                </div>
                ${notes ? `<div class="text-xs text-gray-500 italic">Catatan: ${notes}</div>` : ''}
            </div>
            <div class="flex flex-col items-end gap-2">
                <button type="button" class="text-xs text-red-600 hover:underline removeItem">
                    Hapus
                </button>
            </div>
        `;

        // Hidden inputs buat dikirim ke backend
        const hidProduct = document.createElement('input');
        hidProduct.type  = 'hidden';
        hidProduct.name  = `items[${itemsIndex}][product_id]`;
        hidProduct.value = prodId;

        const hidQty     = document.createElement('input');
        hidQty.type      = 'hidden';
        hidQty.name      = `items[${itemsIndex}][qty]`;
        hidQty.value     = qty;

        const hidPrice   = document.createElement('input');
        hidPrice.type    = 'hidden';
        hidPrice.name    = `items[${itemsIndex}][unit_price]`;
        hidPrice.value   = price;

        const hidNotes   = document.createElement('input');
        hidNotes.type    = 'hidden';
        hidNotes.name    = `items[${itemsIndex}][notes]`;
        hidNotes.value   = notes;

        wrapper.appendChild(hidProduct);
        wrapper.appendChild(hidQty);
        wrapper.appendChild(hidPrice);
        wrapper.appendChild(hidNotes);

        // Tombol hapus
        const removeBtn = wrapper.querySelector('.removeItem');
        removeBtn.addEventListener('click', () => {
            wrapper.remove();
            if (!itemsPanel.querySelector('[data-item-subtotal]') && noItemsHint) {
                noItemsHint.classList.remove('hidden');
            }
            recalcTotal();
        });

        itemsPanel.appendChild(wrapper);
        itemsIndex++;
        recalcTotal();
    }

    if (btnAddItem && itemProduct && itemQty && itemsPanel) {
        btnAddItem.addEventListener('click', () => {
            const opt = itemProduct.options[itemProduct.selectedIndex];
            const prodId = itemProduct.value;

            if (!prodId) {
                alert('Pilih produk terlebih dahulu.');
                return;
            }

            const prodName = opt.textContent.trim();
            const price    = parseFloat(opt.getAttribute('data-price') || '0');
            const qty      = parseFloat(itemQty.value || '0');
            const notes    = (itemNotes.value || '').trim();

            if (!qty || qty <= 0) {
                alert('Qty harus lebih dari 0.');
                return;
            }

            addItemRow(prodId, prodName, price, qty, notes);

            // reset mini form
            itemProduct.selectedIndex = 0;
            itemQty.value = 1;
            itemNotes.value = '';
        });
    }
});
</script>

@endsection
