@extends('layouts.marketingapp', ['title' => 'Dashboard'])

@section('content')
    <div class="page-toolbar">
        <div>
            <h1 class="section-title">Dashboard Marketing</h1>
            <p class="section-subtitle">Kelengkapan foto menu dan menu yang tampil di website.</p>
        </div>
        <a href="{{ route('marketingapp.catalog.index') }}" class="btn-primary">Buka Katalog Foto Menu</a>
    </div>

    <section class="sales-stats-grid mt-4">
        <article class="sales-stat-card">
            <p class="stat-label">Menu Aktif</p>
            <p class="sales-stat-value text-slate-800">{{ number_format($stats['total']) }}</p>
            <p class="stat-meta">Menu yang dijual saat ini</p>
        </article>
        <article class="sales-stat-card">
            <p class="stat-label">Sudah Ada Foto</p>
            <p class="sales-stat-value text-brand-600">{{ number_format($stats['with_photo']) }}</p>
            <p class="stat-meta">
                <a href="{{ route('marketingapp.catalog.index', ['foto' => 'belum']) }}" class="underline">
                    {{ number_format($stats['total'] - $stats['with_photo']) }} belum ada foto
                </a>
            </p>
        </article>
        <article class="sales-stat-card">
            <p class="stat-label">Tampil di Website</p>
            <p class="sales-stat-value text-emerald-600">{{ number_format($stats['on_website']) }}</p>
            <p class="stat-meta">
                <a href="{{ route('marketingapp.catalog.index', ['website' => 'tampil']) }}" class="underline">Lihat daftarnya</a>
            </p>
        </article>
        <article class="sales-stat-card">
            <p class="stat-label">Di Website Tanpa Foto</p>
            <p class="sales-stat-value {{ $stats['website_without_photo'] > 0 ? 'text-rose-600' : 'text-slate-400' }}">
                {{ number_format($stats['website_without_photo']) }}
            </p>
            <p class="stat-meta">Perlu foto sebelum terbit</p>
        </article>
    </section>

    @if ($needsPhoto->isNotEmpty())
        <section class="table-shell mt-6">
            <div class="table-head">Perlu Foto Dulu</div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>SKU (nama di website)</th>
                            <th>Menu</th>
                            <th class="text-right">Harga Jual</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($needsPhoto as $product)
                            <tr>
                                <td class="font-medium">{{ $product->sku }}</td>
                                <td>{{ $product->name }}</td>
                                <td class="text-right">Rp {{ number_format((float) $product->base_price, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($stats['website_without_photo'] > $needsPhoto->count())
                <div class="px-4 py-3 text-sm">
                    <a href="{{ route('marketingapp.catalog.index', ['website' => 'tampil', 'foto' => 'belum']) }}" class="underline">
                        Lihat semua {{ number_format($stats['website_without_photo']) }} menu
                    </a>
                </div>
            @endif
        </section>
    @endif
@endsection
