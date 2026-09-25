@extends('layouts.ownerapp', ['title' => 'Audit Logs'])

@section('content')
@php
  use App\Support\UiLabel;
  $totalLogs = method_exists($logs, 'total') ? $logs->total() : $logs->count();
@endphp

<section class="dashboard-hero">
  <h1 class="dashboard-hero-title">Audit Logs</h1>
  <p class="dashboard-hero-subtitle">
    Telusuri riwayat aktivitas user berdasarkan tanggal, user, entity, dan action.
  </p>
</section>

<section class="stats-grid mt-4">
  <article class="stat-card">
    <p class="stat-label">Total Log</p>
    <p class="stat-value text-brand-600">{{ number_format($totalLogs) }}</p>
    <p class="stat-meta">Hasil filter saat ini</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Rentang Tanggal</p>
    <p class="stat-value text-xl">{{ $dateFrom ?? '-' }}</p>
    <p class="stat-meta">s/d {{ $dateTo ?? '-' }}</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Entity Filter</p>
    <p class="stat-value text-xl">{{ $entity ? UiLabel::auditEntity($entity) : 'Semua' }}</p>
    <p class="stat-meta">Entity yang ditampilkan</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Action Filter</p>
    <p class="stat-value text-xl">{{ $action ? UiLabel::auditAction($action) : 'Semua' }}</p>
    <p class="stat-meta">Jenis aksi</p>
  </article>
</section>

<section class="table-shell mt-4">
  <x-table-toolbar title="Riwayat Aktivitas" :action="route('ownerapp.audit.index')"
    :filters="[
      ['type' => 'date-range', 'label' => 'Periode', 'from' => 'date_from', 'to' => 'date_to',
       'value' => [$dateFrom ?? null, $dateTo ?? null]],
      ['type' => 'select', 'name' => 'user_id', 'label' => 'User', 'placeholder' => 'Semua user',
       'options' => $users->pluck('name', 'id'), 'value' => $userId ?? null],
      ['type' => 'select', 'name' => 'entity', 'label' => 'Entity', 'placeholder' => 'Semua entity',
       'options' => collect($availableEntities)->mapWithKeys(fn ($e) => [$e => \App\Support\UiLabel::auditEntity($e)]),
       'value' => $entity ?? null],
      ['type' => 'select', 'name' => 'action', 'label' => 'Action', 'placeholder' => 'Semua action',
       'options' => collect($availableActions)->mapWithKeys(fn ($a) => [$a => \App\Support\UiLabel::auditAction($a)]),
       'value' => $action ?? null],
    ]" />
  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>User</th>
          <th>Entity</th>
          <th>Action</th>
          <th>Message</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($logs as $log)
          @php
            $actionLower = strtolower($log->action ?? '');
            $actionClass = str_contains($actionLower, 'delete')
              ? 'bg-rose-100 text-rose-700'
              : (str_contains($actionLower, 'create') || str_contains($actionLower, 'store')
                ? 'bg-emerald-100 text-emerald-700'
                : (str_contains($actionLower, 'update') || str_contains($actionLower, 'reset')
                  ? 'bg-sky-100 text-sky-700'
                  : 'bg-slate-100 text-slate-700'));
          @endphp
          <tr>
            <td class="text-xs whitespace-nowrap">{{ $log->created_at?->format('d M Y H:i:s') }}</td>
            <td>{{ $log->user->name ?? '-' }}</td>
            <td class="text-xs">
              {{ UiLabel::auditEntity($log->entity) }}
              @if($log->entity_id)
                <span class="text-slate-400">#{{ $log->entity_id }}</span>
              @endif
              @if($log->purchase_order_id)
                <span class="text-slate-400">(PO #{{ $log->purchase_order_id }})</span>
              @endif
            </td>
            <td>
              <span class="status-badge {{ $actionClass }}">{{ UiLabel::auditAction($log->action) }}</span>
            </td>
            <td class="text-xs">{{ $log->message }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="py-8 text-center text-sm text-slate-500">Belum ada data audit.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if(method_exists($logs, 'links'))
    <div class="border-t border-slate-200 px-5 py-3">
      {{ $logs->links() }}
    </div>
  @endif
</section>
@endsection
