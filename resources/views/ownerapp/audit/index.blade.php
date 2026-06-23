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

<section class="form-shell mt-4">
  <form method="GET" action="{{ route('ownerapp.audit.index') }}" class="grid grid-cols-1 gap-3 md:grid-cols-6">
    <div>
      <label class="mb-1.5 block">Dari Tanggal</label>
      <input type="date" name="date_from" value="{{ $dateFrom ?? '' }}">
    </div>

    <div>
      <label class="mb-1.5 block">Sampai Tanggal</label>
      <input type="date" name="date_to" value="{{ $dateTo ?? '' }}">
    </div>

    <div>
      <label class="mb-1.5 block">User</label>
      <select name="user_id">
        <option value="">Semua</option>
        @foreach($users as $u)
          <option value="{{ $u->id }}" @selected(($userId ?? null) == $u->id)>
            {{ $u->name }}
          </option>
        @endforeach
      </select>
    </div>

    <div>
      <label class="mb-1.5 block">Entity</label>
      <select name="entity">
        <option value="">Semua</option>
        @foreach($availableEntities as $e)
          <option value="{{ $e }}" @selected(($entity ?? null) === $e)>
            {{ UiLabel::auditEntity($e) }}
          </option>
        @endforeach
      </select>
    </div>

    <div>
      <label class="mb-1.5 block">Action</label>
      <select name="action">
        <option value="">Semua</option>
        @foreach($availableActions as $a)
          <option value="{{ $a }}" @selected(($action ?? null) === $a)>
            {{ UiLabel::auditAction($a) }}
          </option>
        @endforeach
      </select>
    </div>

    <div class="flex items-end gap-2">
      <button type="submit" class="btn-primary w-full">Lihat</button>
      <a href="{{ route('ownerapp.audit.index') }}" class="btn-ghost w-full text-center">Reset</a>
    </div>
  </form>
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
  <div class="table-head">Riwayat Aktivitas</div>
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
