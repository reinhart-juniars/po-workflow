@extends('layouts.accountingapp', ['title' => 'Monitoring Pembelian Stok'])

@section('content')
  @php
    $canDeleteInventoryPurchase = auth()->user()?->hasAnyRole(['owner', 'superadmin']);
  @endphp

  <h1 class="text-2xl font-bold mb-4">Monitoring Pembelian Stok</h1>

  <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
    Input pembelian stok sekarang dilakukan lewat menu <strong>Pengeluaran</strong>. Halaman ini dipakai untuk monitoring histori pembelian stok tunai maupun kredit.
  </div>

  <div class="mb-4 {{ $rangePeriodStatus['has_closed_periods'] ? 'notice-soft-amber' : 'notice-soft-emerald' }}">
    <strong>Status periode:</strong> {{ $rangePeriodStatus['message'] }}
  </div>

  <div class="stats-grid mb-4 grid grid-cols-1 gap-4 md:grid-cols-3">
    <div class="stat-card">
      <div class="stat-label">Total Bahan Baku</div>
      <div class="stat-value-compact text-emerald-700">
        <span class="whitespace-nowrap">Rp {{ number_format((float) ($kpis['raw_material'] ?? 0), 0, ',', '.') }}</span>
      </div>
      <div class="mt-1 text-xs text-slate-500">Pembelian kategori Bahan Baku pada periode terpilih.</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Inventaris</div>
      <div class="stat-value-compact text-sky-700">
        <span class="whitespace-nowrap">Rp {{ number_format((float) ($kpis['fixed_asset'] ?? 0), 0, ',', '.') }}</span>
      </div>
      <div class="mt-1 text-xs text-slate-500">Pembelian kategori Inventaris pada periode terpilih.</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Kemasan</div>
      <div class="stat-value-compact text-amber-700">
        <span class="whitespace-nowrap">Rp {{ number_format((float) ($kpis['packaging'] ?? 0), 0, ',', '.') }}</span>
      </div>
      <div class="mt-1 text-xs text-slate-500">Pembelian kategori Kemasan pada periode terpilih.</div>
    </div>
  </div>

  <form method="GET" action="{{ route('accountingapp.inventory-purchases.index') }}"
        class="grid grid-cols-1 md:grid-cols-5 gap-3">
    <div class="md:col-span-5 flex flex-wrap gap-2">
      <button type="button" class="chip-filter js-date-preset" data-form-scope="inventory-purchases-filter" data-preset="this_month">Bulan Ini</button>
      <button type="button" class="chip-filter js-date-preset" data-form-scope="inventory-purchases-filter" data-preset="last_month">Bulan Lalu</button>
      <button type="button" class="chip-filter js-date-preset" data-form-scope="inventory-purchases-filter" data-preset="this_year">Tahun Berjalan</button>
    </div>

    <div>
      <label class="form-label">Item</label>
      <select name="inventory_item_id" class="form-control">
        <option value="">Semua Item</option>
        @foreach($items as $item)
          <option value="{{ $item->id }}" @selected(($itemId ?? null) == $item->id)>
            {{ $item->name }} - {{ $item->categoryLabel() }}
          </option>
        @endforeach
      </select>
    </div>

    <div>
      <label class="form-label">Dari Tanggal</label>
      <input type="date" name="date_from" value="{{ $dateFrom ?? '' }}"
             data-form-scope="inventory-purchases-filter" data-role="date-from"
             class="form-control">
    </div>

    <div>
      <label class="form-label">Sampai Tanggal</label>
      <input type="date" name="date_to" value="{{ $dateTo ?? '' }}"
             data-form-scope="inventory-purchases-filter" data-role="date-to"
             class="form-control">
    </div>

    <div>
      <label class="form-label">Tipe Pembayaran</label>
      <select name="payment_type" class="form-control">
        <option value="">Semua</option>
        <option value="cash" @selected(($paymentType ?? null) === 'cash')>Tunai</option>
        <option value="payable" @selected(($paymentType ?? null) === 'payable')>Kredit</option>
      </select>
    </div>

    <div class="md:col-span-5 flex justify-end">
      <button class="btn-secondary">Filter</button>
    </div>
  </form>

  <div class="table-card mt-6">
    <div class="table-card-head">Daftar Pembelian Stok</div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="text-left px-4 py-2">Tanggal</th>
            <th class="text-left px-4 py-2">Item</th>
            <th class="text-right px-4 py-2">Total Cost</th>
            <th class="text-left px-4 py-2">Pembayaran</th>
            <th class="text-left px-4 py-2">Supplier</th>
            <th class="text-left px-4 py-2">Catatan</th>
            <th class="text-left px-4 py-2">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($purchases as $purchase)
            <tr class="border-t">
              <td class="px-4 py-2">{{ $purchase->transaction_date->format('d-m-Y') }}</td>
              <td class="px-4 py-2">
                {{ $purchase->item->name ?? '-' }}
                @if($purchase->item)
                  <div class="text-xs text-slate-500">{{ $purchase->item->categoryLabel() }}</div>
                @endif
              </td>
              <td class="px-4 py-2 text-right">Rp {{ number_format((float) $purchase->total_value, 0, ',', '.') }}</td>
              <td class="px-4 py-2">{{ $purchase->payment_type === 'cash' ? 'Tunai' : 'Kredit' }}</td>
              <td class="px-4 py-2">{{ $purchase->supplier_name ?: '-' }}</td>
              <td class="px-4 py-2">
                <div>{{ $purchase->notes ?: '-' }}</div>
                @if($purchase->period_closed)
                  <div class="mt-1">
                    <span class="badge-soft-slate">
                      Periode Tertutup
                    </span>
                  </div>
                @endif
              </td>
              <td class="px-4 py-2">
                <div class="flex flex-wrap gap-3">
                  <a href="{{ route('accountingapp.inventory-purchases.edit', $purchase) }}" class="btn-link">
                    Edit
                  </a>
                  @if($canDeleteInventoryPurchase)
                    <form method="POST" action="{{ route('accountingapp.inventory-purchases.destroy', $purchase) }}"
                          onsubmit="return confirm('Hapus pembelian stok ini?')">
                      @csrf
                      @method('DELETE')
                      <button type="submit" class="btn-link-danger">Hapus</button>
                    </form>
                  @endif
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="7" class="px-4 py-6 text-center text-gray-500">Belum ada pembelian stok.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4">
      {{ $purchases->links() }}
    </div>
  </div>

  <script>
    (() => {
      const applyPreset = (scope, preset) => {
        const fromInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-from"]`);
        const toInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-to"]`);

        if (!fromInput || !toInput) {
          return;
        }

        const now = new Date();
        const pad = (value) => String(value).padStart(2, '0');
        const format = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

        let fromDate = new Date(now.getFullYear(), now.getMonth(), 1);
        let toDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);

        if (preset === 'last_month') {
          fromDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
          toDate = new Date(now.getFullYear(), now.getMonth(), 0);
        } else if (preset === 'this_year') {
          fromDate = new Date(now.getFullYear(), 0, 1);
          toDate = new Date(now.getFullYear(), 11, 31);
        }

        fromInput.value = format(fromDate);
        toInput.value = format(toDate);
      };

      document.querySelectorAll('.js-date-preset').forEach((button) => {
        button.addEventListener('click', () => applyPreset(button.dataset.formScope, button.dataset.preset));
      });
    })();
  </script>
@endsection
