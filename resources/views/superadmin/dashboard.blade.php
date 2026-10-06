@extends('layouts.superadmin', ['title' => 'Dashboard'])

@section('content')
@php
  $stats = $stats ?? [
      'users' => 0,
      'active_customers' => 0,
      'due_receivables' => 0,
      'open_payables' => 0,
  ];
@endphp

<section class="dashboard-hero">
  <h1 class="dashboard-hero-title">Superadmin Dashboard</h1>
  <p class="dashboard-hero-subtitle">
    Ringkasan sistem untuk kontrol lintas modul. Fokus dashboard ini pada akun, pelanggan, dan indikator keuangan yang perlu diawasi.
  </p>
</section>

<section class="stats-grid mt-4">
  <article class="stat-card">
    <p class="stat-label">Total Users</p>
    <p class="stat-value text-brand-600">{{ number_format($stats['users']) }}</p>
    <p class="stat-meta">Akun terdaftar di sistem</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Customer Aktif</p>
    <p class="stat-value text-emerald-600">{{ number_format($stats['active_customers']) }}</p>
    <p class="stat-meta">Pelanggan yang masih aktif dipakai operasional</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Piutang Jatuh Tempo</p>
    <p class="stat-value text-amber-600">{{ number_format($stats['due_receivables']) }}</p>
    <p class="stat-meta">PO receivable yang due hari ini atau sudah lewat</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Hutang Terbuka</p>
    <p class="stat-value text-rose-600">{{ number_format($stats['open_payables']) }}</p>
    <p class="stat-meta">Tagihan supplier berstatus belum dibayar atau dibayar sebagian</p>
  </article>
</section>

<section class="mt-4">
  <article class="table-shell">
    <div class="table-head">Kontrol Sistem</div>
    <div class="space-y-3 p-4 text-sm text-slate-600">
      <p>
        Periode accounting bulan ini:
        <strong>{{ $currentPeriodLabel ?? '-' }}</strong>
        <span class="{{ ($currentPeriodClosed ?? false) ? 'text-amber-700' : 'text-emerald-700' }}">
          {{ ($currentPeriodClosed ?? false) ? 'Tertutup' : 'Masih aktif' }}
        </span>
      </p>
      <p>
        @if(!empty($currentPeriodClosedAt))
          Ditutup pada {{ $currentPeriodClosedAt }}.
        @else
          Belum ada penutupan periode untuk bulan berjalan.
        @endif
      </p>
      <p>Penutupan periode terakhir: <strong>{{ $latestClosedPeriodLabel ?? '-' }}</strong></p>
    </div>
  </article>
</section>
@endsection
