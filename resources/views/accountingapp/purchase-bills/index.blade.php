@extends('layouts.accountingapp', ['title' => 'Tagihan Pembelian'])

{{--
  Antrean Tagihan Pembelian dari gudang (revisi 7 Okt 2026): gudang belanja,
  accounting membayar. Tab per meja; hutang supplier diurutkan jatuh tempo.
--}}
@php
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $tabLabels = ['menunggu' => 'Menunggu dibayar', 'hutang' => 'Hutang supplier', 'dibayar' => 'Dibayar', 'gudang' => 'Masih di gudang'];
    $badge = fn (string $status) => match ($status) {
        \App\Models\PurchaseBill::STATUS_SUBMITTED => 'badge-soft-amber',
        \App\Models\PurchaseBill::STATUS_CREDIT => 'badge-soft-brand',
        \App\Models\PurchaseBill::STATUS_PAID => 'badge-soft-emerald',
        \App\Models\PurchaseBill::STATUS_RETURNED => 'badge-soft-rose',
        default => 'badge-soft-slate',
    };
@endphp

@section('content')
    <div class="page-toolbar">
        <div>
            <h1>Tagihan Pembelian</h1>
            <p class="section-subtitle">Belanja bahan baku yang ditagihkan gudang. Barangnya sudah masuk stok; hutangnya menunggu dibayar dari sini.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-success mt-4">{{ session('status') }}</div>
    @endif

    <section class="stats-grid mt-6">
        <article class="stat-card">
            <p class="stat-label">Menunggu dibayar</p>
            <p class="stat-value">{{ $rp($totals['menunggu']) }}</p>
            <p class="stat-meta">{{ $counts['menunggu'] }} tagihan diajukan gudang</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">Hutang supplier</p>
            <p class="stat-value">{{ $rp($totals['hutang']) }}</p>
            <p class="stat-meta {{ $totals['jatuh_tempo'] > 0 ? 'text-rose-600' : '' }}">
                {{ $totals['jatuh_tempo'] > 0 ? $totals['jatuh_tempo'].' sudah jatuh tempo' : 'Belum ada yang jatuh tempo' }}
            </p>
        </article>
        <article class="stat-card">
            <p class="stat-label">Masih di gudang</p>
            <p class="stat-value">{{ $counts['gudang'] }}</p>
            <p class="stat-meta">Draft atau dikembalikan, belum diajukan</p>
        </article>
    </section>

    <nav class="tab-pills mt-6" aria-label="Status tagihan">
        @foreach ($tabLabels as $key => $label)
            <a href="{{ route('accountingapp.purchase-bills.index', ['tab' => $key]) }}"
               class="tab-pill {{ $tab === $key ? 'is-active' : '' }}" @if ($tab === $key) aria-current="page" @endif>
                {{ $label }}
                @if ($counts[$key] > 0)
                    <span class="tab-pill-count">{{ $counts[$key] }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    <section class="table-shell mt-4">
        <x-table-toolbar :title="$tabLabels[$tab]" :action="route('accountingapp.purchase-bills.index')"
            :meta="number_format($bills->total(), 0, ',', '.').' tagihan'"
            search="q" :search-value="$q" search-placeholder="Cari nomor / supplier" :keep="['tab' => $tab]" />
        <div class="data-table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nomor</th>
                        <th>Tanggal</th>
                        <th>Supplier</th>
                        <th>Sumber</th>
                        <th class="text-right">Total</th>
                        <th>Status</th>
                        <th><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bills as $bill)
                        <tr>
                            <td class="font-medium">
                                <a href="{{ route('accountingapp.purchase-bills.show', $bill) }}" class="text-brand-600 hover:underline">{{ $bill->number }}</a>
                                @unless ($bill->receipt_path)
                                    <span class="badge-soft-slate ml-1" title="Gudang belum melampirkan foto nota">tanpa nota</span>
                                @endunless
                            </td>
                            <td class="whitespace-nowrap">{{ $bill->bill_date->translatedFormat('d M Y') }}</td>
                            <td>{{ $bill->displaySupplier() }}</td>
                            <td class="text-slate-500">{{ $bill->requisition?->number ?? 'Belanja lepas' }}</td>
                            <td class="text-right whitespace-nowrap">{{ $rp($bill->total) }}</td>
                            <td>
                                <span class="{{ $badge($bill->status) }}">{{ $bill->statusLabel() }}</span>
                                @if ($bill->status === \App\Models\PurchaseBill::STATUS_CREDIT && $bill->due_date)
                                    <div class="mt-1 text-xs {{ $bill->due_date->isPast() ? 'text-rose-600' : 'text-slate-500' }}">
                                        jatuh tempo {{ $bill->due_date->translatedFormat('d M Y') }}
                                    </div>
                                @endif
                            </td>
                            <td class="text-right">
                                <a href="{{ route('accountingapp.purchase-bills.show', $bill) }}" class="btn-gray">{{ $bill->isPayable() ? 'Proses' : 'Lihat' }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-10 text-center text-slate-500">
                                {{ $tab === 'menunggu' ? 'Tidak ada tagihan yang menunggu dibayar.' : 'Belum ada tagihan di sini.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($bills->hasPages())
            <div class="border-t border-slate-200 px-6 py-3">{{ $bills->links() }}</div>
        @endif
    </section>
@endsection
