@extends('layouts.adminapp', ['title' => 'Laporan PO'])

@section('content')
@php
  use App\Support\UiLabel;
  $draftCount = $orders->where('status', 'draft')->count();
  $inProgressCount = $orders->where('status', 'in_progress')->count();
  $completedCount = $orders->where('status', 'completed')->count();
  $statusLabels = UiLabel::purchaseOrderStatusOptions();
@endphp

<section class="dashboard-hero">
  <div class="page-toolbar">
    <div>
      <h1 class="dashboard-hero-title">Laporan Purchase Order</h1>
      <p class="dashboard-hero-subtitle">
        Monitor volume PO berdasarkan periode dan status. Gunakan export untuk kebutuhan rekap eksternal.
      </p>
    </div>
  </div>
  <x-table-toolbar inline class="mt-4" :action="route('adminapp.reports.orders')"
    :filters="[
      ['type' => 'date-range', 'label' => 'Periode', 'from' => 'date_from', 'to' => 'date_to',
     'value' => [$dateFrom, $dateTo]],
      ['type' => 'text', 'name' => 'menu', 'label' => 'Cari Menu', 'placeholder' => 'Nama menu...',
       'value' => $menuQuery ?? null],
      ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'placeholder' => 'Semua',
     'options' => $statusLabels, 'value' => $status ?: null],
    ]" />
</section>

@include('partials.report-export-actions', [
  'excelUrl' => route('adminapp.reports.orders.export.excel', request()->query()),
  'pdfUrl' => route('adminapp.reports.orders.export.pdf', request()->query()),
  'caption' => 'Export laporan PO mengikuti filter tanggal dan status yang sedang dipilih.',
])

<section class="stats-grid mt-4">
  <article class="stat-card">
    <p class="stat-label">Total PO</p>
    <p class="stat-value text-brand-600">{{ number_format($ordersCount) }}</p>
    <p class="stat-meta">Periode {{ $dateFrom }} s/d {{ $dateTo }}</p>
  </article>

  <article class="stat-card">
    <p class="stat-label">Draft</p>
    <p class="stat-value text-amber-600">{{ number_format($draftCount) }}</p>
    <p class="stat-meta">Belum diproses ke produksi</p>
  </article>

  <article class="stat-card">
    <p class="stat-label">{{ UiLabel::purchaseOrderStatus('in_progress') }}</p>
    <p class="stat-value text-sky-600">{{ number_format($inProgressCount) }}</p>
    <p class="stat-meta">Sedang diproses</p>
  </article>

  <article class="stat-card">
    <p class="stat-label">{{ UiLabel::purchaseOrderStatus('completed') }}</p>
    <p class="stat-value text-emerald-600">{{ number_format($completedCount) }}</p>
    <p class="stat-meta">Siap proses pengiriman</p>
  </article>
</section>

<section class="table-shell mt-4">
  <div class="table-head">Daftar Purchase Order</div>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>PO Number</th>
          <th>Customer</th>
          <th>Detail Menu</th>
          <th>Status</th>
          <th>Total</th>
          <th>Created At</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($orders as $o)
          @php
            $isDelivered = $o->hasDeliveredDeliveryOrder();
            $isEditLocked = $o->isCompleted() || $isDelivered;
            $statusClass = match ($o->status) {
              'draft' => 'bg-amber-100 text-amber-700',
              'in_progress' => 'bg-sky-100 text-sky-700',
              'completed' => 'bg-emerald-100 text-emerald-700',
              default => 'bg-slate-100 text-slate-700',
            };
          @endphp
          <tr>
            <td class="font-mono text-xs">{{ $o->po_number }}</td>
            <td>{{ $o->customer->name ?? '-' }}</td>
            <td>
              @if($o->items->isNotEmpty())
                <details>
                  <summary class="cursor-pointer text-brand-600">Lihat {{ $o->items->count() }} menu</summary>
                  <ul class="mt-2 list-disc pl-5 text-xs text-slate-600 space-y-1">
                    @foreach($o->items as $item)
                      <li>
                        {{ $item->product->name ?? 'Produk' }} x{{ (int) $item->qty }}
                        @if($item->notes)
                          - {{ $item->notes }}
                        @endif
                      </li>
                    @endforeach
                  </ul>
                </details>
              @else
                <span class="text-slate-400 text-xs">Tidak ada item</span>
              @endif
            </td>
            <td><span class="status-badge {{ $statusClass }}">{{ UiLabel::purchaseOrderStatus($o->status) }}</span></td>
            <td class="font-semibold">Rp {{ number_format((float) ($o->total_amount ?? 0), 0, ',', '.') }}</td>
            <td>{{ $o->created_at?->format('d M Y H:i') }}</td>
            <td>
              @unless ($isEditLocked)
                <x-row-action kind="edit" label="Edit PO" :href="route('adminapp.orders.edit', $o->id)" />
              @endunless
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" class="py-8 text-center text-sm text-slate-500">Tidak ada data.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
