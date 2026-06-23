@extends('layouts.productionapp', ['title' => 'Dashboard'])

@section('content')
@php
  use App\Support\UiLabel;
  $allPos = $allPos ?? $pos;
  $inProgressCount = $allPos->count();
  $withSchedule = $allPos->filter(fn ($po) => optional($po->spks->first())->scheduled_at)->count();
  $withoutSchedule = $inProgressCount - $withSchedule;
  $filterTabs = [
    ['key' => 'all', 'label' => 'Semua', 'count' => $inProgressCount],
    ['key' => 'scheduled', 'label' => 'Sudah Dijadwalkan', 'count' => $withSchedule],
    ['key' => 'unscheduled', 'label' => 'Belum Ada Jadwal', 'count' => $withoutSchedule],
  ];
@endphp

<section class="dashboard-hero">
  <div class="page-toolbar">
    <div>
      <h1 class="dashboard-hero-title">Production Dashboard</h1>
      <p class="dashboard-hero-subtitle xl:whitespace-nowrap">
        Daftar PO yang sedang berjalan di produksi. <br>
        Pantau jadwal dan lanjutkan ke detail untuk update status selesai.
      </p>
      <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($filterTabs as $filterTab)
          <a
            href="{{ route('productionapp.dashboard', array_filter(['schedule_filter' => $filterTab['key'] !== 'all' ? $filterTab['key'] : null])) }}"
            class="chip {{ $scheduleFilter === $filterTab['key'] ? 'bg-brand-500 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50' }}"
          >
            {{ $filterTab['label'] }} ({{ number_format($filterTab['count']) }})
          </a>
        @endforeach
      </div>
    </div>
  </div>
</section>

<section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 mt-4">
  <article class="stat-card">
    <p class="stat-label">PO {{ UiLabel::purchaseOrderStatus('in_progress') }}</p>
    <p class="stat-value text-sky-600">{{ number_format($inProgressCount) }}</p>
    <p class="stat-meta">Total antrian produksi aktif</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Sudah Ada Jadwal</p>
    <p class="stat-value text-emerald-600">{{ number_format($withSchedule) }}</p>
    <p class="stat-meta">PO dengan slot produksi</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Belum Ada Jadwal</p>
    <p class="stat-value text-amber-600">{{ number_format($withoutSchedule) }}</p>
    <p class="stat-meta">Perlu penjadwalan lanjutan</p>
  </article>
</section>

<section class="mt-4 space-y-3 lg:hidden">
  <div>
    <h2 class="section-title">Daftar PO Produksi</h2>
    <p class="section-subtitle">
      Tampilan ringkas untuk HP. Menampilkan {{ number_format($pos->count()) }} PO pada filter
      {{ collect($filterTabs)->firstWhere('key', $scheduleFilter)['label'] ?? 'Semua' }}.
    </p>
  </div>

  @forelse ($pos as $po)
    @php
      $spk = $po->spks->first();
      $schedule = $spk?->scheduled_at
        ? \Carbon\Carbon::parse($spk->scheduled_at)
        : null;
      $scheduleTone = $schedule ? 'badge-soft-emerald' : 'badge-soft-amber';
      $scheduleLabel = $schedule ? 'Sudah dijadwalkan' : 'Belum ada jadwal';
    @endphp
    <article class="section-card">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-sm font-semibold text-slate-900">{{ $po->customer->name ?? '-' }}</p>
          <p class="mt-1 font-mono text-xs text-slate-500">{{ $po->po_number }}</p>
        </div>
        <span class="{{ $scheduleTone }}">{{ $scheduleLabel }}</span>
      </div>

      <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
        <div>
          <p class="metric-label">Area</p>
          <p class="font-semibold text-slate-800">{{ $po->area->name ?? '-' }}</p>
        </div>
        <div>
          <p class="metric-label">Tanggal Produksi</p>
          <p class="font-semibold text-slate-800">{{ $schedule ? $schedule->format('d/m/Y') : '-' }}</p>
        </div>
        <div>
          <p class="metric-label">Jam Produksi</p>
          <p class="font-semibold text-slate-800">{{ $schedule ? $schedule->format('H:i') : '-' }}</p>
        </div>
        <div>
          <p class="metric-label">Status</p>
          <p class="font-semibold text-sky-700">{{ UiLabel::purchaseOrderStatus('in_progress') }}</p>
        </div>
      </div>

      <a href="{{ route('productionapp.orders.show', $po->id) }}" class="btn-primary mt-4 w-full">
        Lihat Detail
      </a>
    </article>
  @empty
    <div class="section-card text-sm text-slate-500">
      Tidak ada PO berstatus {{ UiLabel::purchaseOrderStatus('in_progress') }}.
    </div>
  @endforelse
</section>

<section class="table-shell mt-4 hidden lg:block">
  <div class="table-head">
    Daftar PO Produksi
    <span class="ml-2 text-xs font-normal text-slate-500">
      {{ collect($filterTabs)->firstWhere('key', $scheduleFilter)['label'] ?? 'Semua' }} • {{ number_format($pos->count()) }} PO
    </span>
  </div>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>PO Number</th>
          <th>Customer</th>
          <th>Area</th>
          <th>Tanggal Produksi</th>
          <th>Jam Produksi</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($pos as $po)
          @php
            $spk = $po->spks->first();
            $schedule = $spk?->scheduled_at
              ? \Carbon\Carbon::parse($spk->scheduled_at)
              : null;
          @endphp
          <tr>
            <td class="font-mono text-xs">{{ $po->po_number }}</td>
            <td>{{ $po->customer->name ?? '-' }}</td>
            <td>{{ $po->area->name ?? '-' }}</td>
            <td>{{ $schedule ? $schedule->format('d/m/Y') : '-' }}</td>
            <td>{{ $schedule ? $schedule->format('H:i') : '-' }}</td>
            <td>
              <a href="{{ route('productionapp.orders.show', $po->id) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-500">
                Lihat detail
              </a>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="6" class="py-8 text-center text-sm text-slate-500">
              Tidak ada PO berstatus {{ UiLabel::purchaseOrderStatus('in_progress') }}.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
