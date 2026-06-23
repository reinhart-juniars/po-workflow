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
</section>

<section class="form-shell mt-4">
  <form method="GET" action="{{ route('adminapp.reports.orders') }}" class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="form-label">Dari Tanggal</label>
          <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control">
        </div>

        <div>
          <label class="form-label">Sampai Tanggal</label>
          <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control">
        </div>
      </div>

      <div>
        <label class="form-label">Cari Menu</label>
        <input type="text" name="menu" value="{{ $menuQuery ?? '' }}" class="form-control" placeholder="Nama menu...">
        <p class="mt-1 text-xs text-slate-500">Tampilkan PO yang memuat menu dengan nama mengandung kata kunci ini.</p>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <button class="btn-primary w-full" type="submit">Lihat</button>
        <a href="{{ route('adminapp.reports.orders') }}" class="btn-ghost w-full text-center">Reset</a>
      </div>
    </div>

    <div>
      <label class="form-label">Status</label>
      <select name="status" class="form-control">
        <option value="">Semua</option>
        @foreach($statusLabels as $statusValue => $statusText)
          <option value="{{ $statusValue }}" @selected($status === $statusValue)>{{ $statusText }}</option>
        @endforeach
      </select>
    </div>
  </form>

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
                <a href="{{ route('adminapp.orders.edit', $o->id) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-500">
                  Edit PO
                </a>
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
