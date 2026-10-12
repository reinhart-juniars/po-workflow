@extends('layouts.adminapp')

@section('title', $customer->name)

{{--
  Profil customer (mini CRM). Semua angka dari PO completed
  (CustomerInsightService); PO draft/batal tetap terlihat di riwayat.
  Grafik omzet: satu seri, batang <= 24 px berujung bulat 4 px, label langsung
  hanya di bulan tertinggi, tooltip saat hover (CSS), tabel di <details>.
--}}
@php
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0';
    $statusLabels = \App\Services\CustomerInsightService::statusOptions();
    $statusBadge = ['baru' => 'badge-soft-brand', 'aktif' => 'badge-soft-emerald', 'lama' => 'badge-soft-amber', 'belum' => 'badge-soft-slate'];
    $poBadge = fn (string $s) => match ($s) { 'completed' => 'badge-soft-emerald', 'draft' => 'badge-soft-slate', default => 'badge-soft-amber' };
    $poLabel = fn (string $s) => match ($s) { 'completed' => 'Selesai', 'draft' => 'Draft', default => ucfirst(str_replace('_', ' ', $s)) };
    $max = max(1, (float) collect($monthly)->max('revenue'));
    $peakIndex = collect($monthly)->search(fn ($m) => (float) $m['revenue'] === (float) collect($monthly)->max('revenue') && $m['revenue'] > 0);
@endphp

@section('content')
    <div class="page-toolbar">
        <div>
            <p class="text-sm text-slate-500"><a href="{{ route('adminapp.customers.index') }}" class="hover:underline">Customer</a> ›</p>
            <h1 class="mt-1">{{ $customer->name }}</h1>
            <p class="section-subtitle">
                {{ $customer->phone ?: 'Tanpa telepon' }}
                @if ($customer->area) · {{ $customer->area->name }} @endif
                @if ($customer->address) · {{ $customer->address }} @endif
            </p>
            <div class="mt-3 flex flex-wrap gap-1.5">
                <span class="{{ $statusBadge[$profile['status']] }}">{{ $statusLabels[$profile['status']] }}</span>
                @if ($customer->is_lapak)<span class="badge-soft-amber">Lapak</span>@endif
                @unless ($customer->active)<span class="badge-soft-slate">Nonaktif</span>@endunless
            </div>
        </div>
        <div class="toolbar-actions">
            <a href="{{ route('adminapp.customers.edit', $customer) }}" class="btn-gray">Ubah data</a>
            <a href="{{ route('adminapp.orders.index') }}" class="btn-primary">Buat PO</a>
        </div>
    </div>

    <section class="stats-grid mt-6">
        <article class="stat-card">
            <p class="stat-label">Total omzet</p>
            <p class="stat-value-compact whitespace-nowrap">{{ $rp($profile['revenue']) }}</p>
            <p class="stat-meta">{{ number_format($profile['po_count'], 0, ',', '.') }} PO selesai{{ $profile['first_order'] ? ' sejak '.$profile['first_order']->translatedFormat('M Y') : '' }}</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">Omzet {{ \App\Services\CustomerInsightService::RECENT_DAYS }} hari</p>
            <p class="stat-value-compact whitespace-nowrap">{{ $rp($profile['recent_revenue']) }}</p>
            <p class="stat-meta">{{ number_format($profile['recent_po_count'], 0, ',', '.') }} PO</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">Rata-rata per PO</p>
            <p class="stat-value-compact whitespace-nowrap">{{ $rp($profile['avg_po_value']) }}</p>
            <p class="stat-meta">{{ $profile['avg_interval_days'] !== null ? 'Order tiap ±'.$num($profile['avg_interval_days']).' hari' : 'Belum ada pola order' }}</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">Order terakhir</p>
            <p class="stat-value-compact whitespace-nowrap">{{ $profile['last_order'] ? $profile['last_order']->translatedFormat('d M Y') : '–' }}</p>
            <p class="stat-meta {{ $profile['status'] === 'lama' ? 'text-amber-700' : '' }}">
                {{ $profile['days_since_last'] !== null ? $profile['days_since_last'].' hari lalu' : 'Belum pernah order' }}
            </p>
        </article>
    </section>

    <div class="crm-grid mt-4">
        <section class="section-card">
            <h2 class="section-title">Omzet per bulan</h2>
            <p class="section-subtitle">12 bulan terakhir, PO selesai.</p>
            @if (collect($monthly)->sum('revenue') > 0)
                <div class="crm-chart mt-6" role="img" aria-label="Grafik omzet per bulan, rincian di tabel di bawah">
                    @foreach ($monthly as $i => $m)
                        <div class="crm-bar-col" tabindex="0">
                            @if ($i === $peakIndex)
                                <span class="crm-bar-value">{{ $rp($m['revenue']) }}</span>
                            @endif
                            <span class="crm-bar" style="height: {{ $m['revenue'] > 0 ? max(2, round($m['revenue'] / $max * 100, 2)) : 0 }}%"></span>
                            <span class="crm-tip">{{ $m['label'] }} · {{ $rp($m['revenue']) }} · {{ $m['po_count'] }} PO</span>
                            <span class="crm-bar-label">{{ $m['label'] }}</span>
                        </div>
                    @endforeach
                </div>
                <details class="crm-table mt-4">
                    <summary>Lihat sebagai tabel</summary>
                    <table class="data-table mt-2">
                        <thead><tr><th>Bulan</th><th class="text-right">PO</th><th class="text-right">Omzet</th></tr></thead>
                        <tbody>
                            @foreach ($monthly as $m)
                                <tr><td>{{ $m['label'] }}</td><td class="text-right">{{ $m['po_count'] }}</td><td class="text-right">{{ $rp($m['revenue']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @else
                <p class="mt-6 text-sm text-slate-500">Belum ada PO selesai dalam 12 bulan terakhir.</p>
            @endif
        </section>

        <section class="section-card">
            <h2 class="section-title">Menu favorit</h2>
            <p class="section-subtitle">Paling banyak dipesan (porsi), semua PO selesai.</p>
            @forelse ($favorites as $i => $menu)
                <div class="crm-fav {{ $i === 0 ? 'mt-5' : '' }}">
                    <span class="crm-fav-rank">{{ $i + 1 }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-950" title="{{ $menu->name }}">{{ $menu->name }}</p>
                        <p class="text-xs text-slate-500">{{ $num($menu->qty) }} porsi · {{ $menu->orders }} PO · {{ $rp($menu->revenue) }}</p>
                    </div>
                </div>
            @empty
                <p class="mt-6 text-sm text-slate-500">Belum ada menu yang dipesan.</p>
            @endforelse
        </section>
    </div>

    <section class="table-shell mt-4">
        <div class="table-head">Riwayat PO</div>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Nomor PO</th><th>Tanggal kirim</th><th>Pembayaran</th><th class="text-right">Porsi</th><th class="text-right">Total</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($orders as $po)
                        <tr>
                            <td><a href="{{ route('adminapp.orders.show', $po) }}" class="font-medium text-brand-600 hover:underline">{{ $po->po_number }}</a></td>
                            <td class="whitespace-nowrap">{{ $po->delivery_date?->translatedFormat('d M Y') ?? '–' }}</td>
                            <td>{{ $po->payment_type === 'cash' ? 'Tunai' : 'Piutang' }}@if ($po->payment_type !== 'cash' && $po->receivable_status) <span class="text-xs text-slate-500">· {{ $po->receivable_status === 'paid' ? 'lunas' : 'belum lunas' }}</span>@endif</td>
                            <td class="text-right">{{ $num($po->total_qty) }}</td>
                            <td class="text-right whitespace-nowrap">{{ $rp($po->total_amount) }}</td>
                            <td><span class="{{ $poBadge($po->status) }}">{{ $poLabel($po->status) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-sm text-slate-500">Belum ada PO untuk customer ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())
            <div class="border-t border-slate-200 px-6 py-3">{{ $orders->links() }}</div>
        @endif
    </section>
@endsection
