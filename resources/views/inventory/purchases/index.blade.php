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


  <div class="table-card mt-6">
    <x-table-toolbar title="Daftar Pembelian Stok" :action="route('accountingapp.inventory-purchases.index')"
      :filters="[
        ['type' => 'date-range', 'label' => 'Periode', 'from' => 'date_from', 'to' => 'date_to',
         'value' => [$dateFrom ?? null, $dateTo ?? null]],
        ['type' => 'select', 'name' => 'inventory_item_id', 'label' => 'Item', 'placeholder' => 'Semua item',
         'options' => $items->mapWithKeys(fn ($i) => [$i->id => $i->name.' - '.$i->categoryLabel()]), 'value' => $itemId ?? null],
        ['type' => 'select', 'name' => 'payment_type', 'label' => 'Pembayaran', 'placeholder' => 'Semua',
         'options' => ['cash' => 'Tunai', 'payable' => 'Kredit'], 'value' => $paymentType ?? null],
      ]" />
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
                <div class="row-actions">
                  <x-row-action kind="edit" :href="route('accountingapp.inventory-purchases.edit', $purchase)" />
                  @if($canDeleteInventoryPurchase)
                    <x-row-action kind="delete" :action="route('accountingapp.inventory-purchases.destroy', $purchase)"
                                  confirm="Hapus pembelian stok ini?" />
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

@endsection
