@extends('layouts.adminapp', ['title' => 'Laporan Delivery'])

@section('content')
@php
  use App\Support\UiLabel;
  $readyCount = $dos->where('status', 'ready')->count();
  $onDeliveryCount = $dos->where('status', 'on_delivery')->count();
  $deliveredCount = $dos->where('status', 'delivered')->count();
  $statusLabels = UiLabel::deliveryStatusOptions();
@endphp

<section class="dashboard-hero">
  <div class="page-toolbar">
    <div>
      <h1 class="dashboard-hero-title">Laporan Delivery</h1>
      <p class="dashboard-hero-subtitle">
        Ringkasan performa delivery order berdasarkan area, driver, dan status pengiriman.
      </p>
    </div>
  </div>
</section>

<section class="form-shell mt-4">
  <form method="GET" action="{{ route('adminapp.reports.delivery') }}" class="grid grid-cols-1 gap-4 xl:grid-cols-3">
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

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <button class="btn-primary w-full" type="submit">Lihat</button>
        <a href="{{ route('adminapp.reports.delivery') }}" class="btn-ghost w-full text-center">Reset</a>
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
  'excelUrl' => route('adminapp.reports.delivery.export.excel', request()->query()),
  'pdfUrl' => route('adminapp.reports.delivery.export.pdf', request()->query()),
  'caption' => 'Export laporan delivery mengikuti filter tanggal dan status yang sedang dipilih.',
])

<section class="stats-grid mt-4">
  <article class="stat-card">
    <p class="stat-label">Total Delivery</p>
    <p class="stat-value text-brand-600">{{ number_format($doCount) }}</p>
    <p class="stat-meta">Periode {{ $dateFrom }} s/d {{ $dateTo }}</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">{{ UiLabel::deliveryStatus('ready') }}</p>
    <p class="stat-value text-amber-600">{{ number_format($readyCount) }}</p>
    <p class="stat-meta">Belum start delivery</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">{{ UiLabel::deliveryStatus('on_delivery') }}</p>
    <p class="stat-value text-sky-600">{{ number_format($onDeliveryCount) }}</p>
    <p class="stat-meta">Sedang proses kirim</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">{{ UiLabel::deliveryStatus('delivered') }}</p>
    <p class="stat-value text-emerald-600">{{ number_format($deliveredCount) }}</p>
    <p class="stat-meta">Sudah diterima customer</p>
  </article>
</section>

<section class="table-shell mt-4">
  <div class="table-head">Daftar Delivery Order</div>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>DO Code</th>
          <th>Area</th>
          <th>Driver</th>
          <th>Recipient</th>
          <th>Status</th>
          <th>Scheduled At</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($dos as $d)
          @php
            $statusClass = match ($d->status) {
              'ready' => 'bg-amber-100 text-amber-700',
              'on_delivery' => 'bg-sky-100 text-sky-700',
              'delivered' => 'bg-emerald-100 text-emerald-700',
              default => 'bg-slate-100 text-slate-700',
            };
          @endphp
          <tr>
            <td class="font-mono text-xs">{{ $d->do_code }}</td>
            <td>{{ $d->area->name ?? '-' }}</td>
            <td>{{ $d->driver->name ?? '-' }}</td>
            <td>{{ $d->recipient_name ?? '-' }}</td>
            <td><span class="status-badge {{ $statusClass }}">{{ UiLabel::deliveryStatus($d->status) }}</span></td>
            <td>{{ $d->scheduled_at?->format('d M Y H:i') }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="6" class="py-8 text-center text-sm text-slate-500">Tidak ada data.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
