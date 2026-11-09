@extends('layouts.adminapp')

@section('title', 'Harga Menu')

@php
    $rupiah = fn ($value) => $value === null ? '-' : 'Rp '.number_format((float) $value, 0, ',', '.');
    $costStatus ??= null;
    $recipeUrl ??= null;
    $masterMenuUrl ??= null;
@endphp

@section('content')
    <div class="page-toolbar">
        <div>
            <h1 class="section-title">{{ $product->name }}</h1>
            <p class="section-subtitle">
                Admin mengisi Harga Non Lapak. Data menu lainnya diatur tim menu di aplikasi Menu › Master Menu.
                @if ($masterMenuUrl)
                    <a href="{{ $masterMenuUrl }}" class="font-semibold underline">Buka di Master Menu</a>
                @endif
            </p>
        </div>
    </div>

    @if ($errors->any())
        <div class="flash-error mt-4">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="form-shell mt-4 space-y-4">
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm md:grid-cols-4">
            <div>
                <dt class="text-xs text-slate-500">SKU</dt>
                <dd class="font-medium text-slate-800">{{ $product->sku ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Satuan</dt>
                <dd class="font-medium text-slate-800">{{ $product->unit }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Resep</dt>
                <dd class="font-medium text-slate-800">
                    @if ($product->recipe)
                        {{ $product->recipe->name }}
                        @if ($recipeUrl)
                            · <a href="{{ $recipeUrl }}" class="underline">rincian HPP</a>
                        @endif
                    @else
                        Tanpa resep
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Status</dt>
                <dd class="font-medium text-slate-800">
                    {{ $product->active ? 'Aktif' : 'Nonaktif' }}{{ $product->is_3s ? ' · Menu 3S' : '' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">HPP Bahan Baku</dt>
                <dd class="font-medium text-slate-800">{{ $rupiah($product->raw_material_cost) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">OHC</dt>
                <dd class="font-medium text-slate-800">{{ $rupiah($product->overhead_cost) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Harga Lapak</dt>
                <dd class="font-medium text-slate-800">{{ $product->lapak_price === null ? 'Pakai Harga Non Lapak' : $rupiah($product->lapak_price) }}</dd>
                @if ($product->lapakFollowsRecipe())
                    <dd class="text-xs text-slate-500">Dari Harga Jual Target resep</dd>
                @endif
            </div>
            <div>
                <dt class="text-xs text-slate-500">Profit (dari Harga Lapak)</dt>
                <dd class="font-medium text-slate-800">{{ $rupiah($product->profit) }}</dd>
            </div>
        </dl>

        @if ($costStatus && $product->recipe_id && ! $product->costFollowsRecipe() && $costStatus['state'] === 'tertahan')
            <div class="cost-source is-held">
                <p class="cost-source-title">HPP masih manual: {{ $costStatus['reason'] }}</p>
                <p class="cost-source-note">Begitu hitungan resepnya lengkap di aplikasi Menu, HPP &amp; OHC menu ini otomatis mengikuti resep.</p>
            </div>
        @endif
    </section>

    <form method="POST" action="{{ route('adminapp.products.update', $product) }}" class="form-shell mt-4 space-y-4">
        @csrf
        @method('PUT')

        <div class="max-w-xs">
            <label for="base_price" class="mb-1.5 block">Harga Non Lapak</label>
            <input id="base_price" type="number" step="0.01" min="0" name="base_price"
                value="{{ old('base_price', (float) $product->base_price > 0 ? $product->base_price : '') }}" required autofocus>
            <p class="mt-1 text-xs text-slate-500">Harga untuk customer non lapak. Tidak memengaruhi profit.</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="submit" class="btn-primary">Simpan</button>
            <a href="{{ route('adminapp.products.index') }}" class="btn-ghost">Batal</a>
        </div>
    </form>

    @if ($product->relationLoaded('priceHistories') && $product->priceHistories->isNotEmpty())
        <section class="form-shell mt-6 space-y-3">
            <div>
                <h2 class="section-title text-base">Riwayat Harga</h2>
                <p class="section-subtitle">
                    Semua perubahan harga & cost tercatat di sini. Transaksi PO/penjualan lama tetap pakai snapshot
                    harga saat itu, jadi mengubah harga master tidak akan mengubah laporan periode sebelumnya.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500">
                            <th class="px-3 py-2">Berlaku Sejak</th>
                            <th class="px-3 py-2 text-right">Bahan Baku</th>
                            <th class="px-3 py-2 text-right">Overhead</th>
                            <th class="px-3 py-2 text-right">Profit</th>
                            <th class="px-3 py-2 text-right">Harga Lapak</th>
                            <th class="px-3 py-2 text-right">Harga Non Lapak</th>
                            <th class="px-3 py-2">Diubah Oleh</th>
                            <th class="px-3 py-2">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($product->priceHistories as $i => $history)
                            @php
                                $previous = $product->priceHistories[$i + 1] ?? null;
                                $priceDelta = $previous
                                    ? (float) $history->base_price - (float) $previous->base_price
                                    : null;
                            @endphp
                            <tr class="{{ $i === 0 ? 'bg-emerald-50/50' : '' }}">
                                <td class="px-3 py-2 align-top">
                                    <div class="font-medium text-slate-700">
                                        {{ $history->effective_from->format('d M Y') }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ $history->effective_from->format('H:i') }}
                                        @if ($i === 0)
                                            <span
                                                class="ml-1 inline-flex items-center rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-700">
                                                Aktif
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-2 align-top text-right">
                                    {{ $history->raw_material_cost !== null ? 'Rp ' . number_format((float) $history->raw_material_cost, 0, ',', '.') : '-' }}
                                </td>
                                <td class="px-3 py-2 align-top text-right">
                                    {{ $history->overhead_cost !== null ? 'Rp ' . number_format((float) $history->overhead_cost, 0, ',', '.') : '-' }}
                                </td>
                                <td class="px-3 py-2 align-top text-right">
                                    {{ $history->profit !== null ? 'Rp ' . number_format((float) $history->profit, 0, ',', '.') : '-' }}
                                </td>
                                <td class="px-3 py-2 align-top text-right">
                                    {{ $history->lapak_price !== null ? 'Rp ' . number_format((float) $history->lapak_price, 0, ',', '.') : '-' }}
                                </td>
                                <td class="px-3 py-2 align-top text-right">
                                    Rp {{ number_format((float) $history->base_price, 0, ',', '.') }}
                                    @if ($priceDelta !== null && abs($priceDelta) > 0.001)
                                        <div class="text-xs {{ $priceDelta > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                            {{ $priceDelta > 0 ? '+' : '' }}Rp
                                            {{ number_format($priceDelta, 0, ',', '.') }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 align-top text-slate-600">
                                    {{ $history->changedBy?->name ?? 'Sistem' }}
                                </td>
                                <td class="px-3 py-2 align-top text-xs text-slate-500">
                                    {{ $history->reason ?? '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

@endsection
