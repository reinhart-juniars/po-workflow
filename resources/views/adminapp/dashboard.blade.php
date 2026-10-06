@extends('layouts.adminapp', ['title' => 'Dashboard'])

@section('content')
@php
  use App\Support\UiLabel;
  $poTodayDraft = $poTodayDraft ?? 0;
  $poTodayProg  = $poTodayProg ?? 0;
  $allPosToday = $allPosToday ?? $posToday;
  $activeCount = $poTodayDraft + $poTodayProg;
  $statusTabs = [
    ['key' => 'all', 'label' => 'Semua', 'count' => $activeCount],
    ['key' => 'draft', 'label' => 'Draft', 'count' => $poTodayDraft],
    ['key' => 'in_progress', 'label' => UiLabel::purchaseOrderStatus('in_progress'), 'count' => $poTodayProg],
  ];
@endphp

<section class="dashboard-hero">
  <div>
    <div>
      <h1 class="dashboard-hero-title">Admin Dashboard</h1>
      <p class="dashboard-hero-subtitle xl:whitespace-nowrap">
        Ringkasan purchase order aktif untuk admin. <br>
        Pantau order baru, order yang sedang diproses, dan akses cepat ke detail order.
      </p>
      <div class="mt-4 flex flex-wrap gap-2">
        <a href="{{ route('adminapp.orders.index') }}" class="btn-primary">Kelola Order</a>
        <a href="{{ route('adminapp.spk.index') }}" class="btn-ghost">Buat SPK</a>
      </div>
      <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($statusTabs as $statusTab)
          <a
            href="{{ route('adminapp.dashboard', array_filter(['status_filter' => $statusTab['key'] !== 'all' ? $statusTab['key'] : null])) }}"
            class="chip {{ ($statusFilter ?? 'all') === $statusTab['key'] ? 'bg-brand-500 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50' }}"
          >
            {{ $statusTab['label'] }} ({{ number_format($statusTab['count']) }})
          </a>
        @endforeach
      </div>
    </div>
  </div>
</section>

<section class="stats-grid mt-4">
  <article class="stat-card">
    <p class="stat-label">Total PO Aktif</p>
    <p class="stat-value">{{ number_format($activeCount) }}</p>
    <p class="stat-meta">Draft + {{ UiLabel::purchaseOrderStatus('in_progress') }}</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Draft</p>
    <p class="stat-value text-amber-600">{{ number_format($poTodayDraft) }}</p>
    <p class="stat-meta">Belum dijadwalkan produksi</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">{{ UiLabel::purchaseOrderStatus('in_progress') }}</p>
    <p class="stat-value text-sky-600">{{ number_format($poTodayProg) }}</p>
    <p class="stat-meta">Sedang diproses tim produksi</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Perlu Tindakan</p>
    <p class="stat-value">{{ number_format($poTodayDraft) }}</p>
    <p class="stat-meta">Draft yang perlu diproses hari ini</p>
  </article>
</section>

<section class="table-shell mt-4">
  <div class="table-head">
    Daftar PO Aktif
    <span class="ml-2 text-xs font-normal text-slate-500">
      {{ collect($statusTabs)->firstWhere('key', $statusFilter ?? 'all')['label'] ?? 'Semua' }} • {{ number_format($posToday->count()) }} PO
    </span>
  </div>

  <div class="space-y-3 p-4 lg:hidden">
    @forelse ($posToday as $row)
      <article class="section-card">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-sm font-semibold text-slate-900">{{ $row->customer->name ?? '-' }}</p>
            <p class="mt-1 font-mono text-xs text-slate-500">{{ $row->po_number }}</p>
          </div>
          <span class="status-badge {{ $row->status === 'draft' ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700' }}">
            {{ UiLabel::purchaseOrderStatus($row->status) }}
          </span>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
          <div>
            <p class="metric-label">Area</p>
            <p class="font-semibold text-slate-800">{{ $row->area->name ?? '-' }}</p>
          </div>
          <div>
            <p class="metric-label">Delivery</p>
            <p class="font-semibold text-slate-800">{{ $row->delivery_date ? \Carbon\Carbon::parse($row->delivery_date)->format('d M Y') : '-' }}</p>
          </div>
          <div class="col-span-2">
            <p class="metric-label">Total</p>
            <p class="font-semibold text-slate-900">Rp {{ number_format((float) ($row->total_amount ?? 0), 0, ',', '.') }}</p>
          </div>
        </div>

        <a href="{{ route('adminapp.orders.show', $row->id) }}" class="btn-primary mt-4 w-full">
          Lihat Detail
        </a>
      </article>
    @empty
      <div class="section-card text-sm text-slate-500">
        Tidak ada PO dengan status draft atau {{ UiLabel::purchaseOrderStatus('in_progress') }}.
      </div>
    @endforelse
  </div>

  <div class="data-table-wrap hidden lg:block">
    <table class="data-table">
      <thead>
        <tr>
          <th>PO Number</th>
          <th>Customer</th>
          <th>Area</th>
          <th>Delivery</th>
          <th>Status</th>
          <th>Total</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($posToday as $row)
          @php
            $statusClass = $row->status === 'draft'
              ? 'bg-amber-100 text-amber-700'
              : 'bg-sky-100 text-sky-700';
          @endphp
          <tr>
            <td class="font-mono text-xs">{{ $row->po_number }}</td>
            <td>{{ $row->customer->name ?? '-' }}</td>
            <td>{{ $row->area->name ?? '-' }}</td>
            <td>{{ $row->delivery_date ? \Carbon\Carbon::parse($row->delivery_date)->format('d M Y') : '-' }}</td>
            <td>
              <span class="status-badge {{ $statusClass }}">
                {{ UiLabel::purchaseOrderStatus($row->status) }}
              </span>
            </td>
            <td class="font-semibold">Rp {{ number_format((float) ($row->total_amount ?? 0), 0, ',', '.') }}</td>
            <td>
              <a href="{{ route('adminapp.orders.show', $row->id) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-500">
                Lihat detail
              </a>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" class="py-8 text-center text-sm text-slate-500">
              Tidak ada PO dengan status draft atau {{ UiLabel::purchaseOrderStatus('in_progress') }}.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
