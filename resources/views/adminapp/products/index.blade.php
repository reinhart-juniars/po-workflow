@extends('layouts.adminapp')

@section('title', 'Harga Menu')

@push('styles')
<style>
    .products-wrap {
        overflow: hidden;
    }
    .products-table {
        width: 100%;
        table-layout: auto;
    }
    /* 9 kolom: sedikit lebih rapat dari .data-table standar (14/600, kalimat
       biasa), tanpa huruf kapital. */
    .products-table th,
    .products-table td {
        padding: 0.75rem 0.625rem;
        font-size: 13px;
        line-height: 1.4;
    }
    .products-table th:first-child,
    .products-table td:first-child {
        padding-left: 1.5rem;
    }
    .products-table .tabular-nums {
        font-variant-numeric: tabular-nums lining-nums;
        font-feature-settings: "tnum" 1, "lnum" 1;
    }
    .products-table .rp {
        color: rgb(148 163 184);
        font-weight: 500;
        margin-right: 0.15rem;
    }
    .products-table .name-cell {
        min-width: 140px;
        max-width: 220px;
        white-space: normal;
        word-break: break-word;
    }
</style>
@endpush

@section('content')
    <div class="page-toolbar">
        <div>
            <h1 class="section-title">Harga Menu</h1>
            <p class="section-subtitle">
                Menu yang bisa dipesan di PO: sudah punya resep, atau ditandai tim menu memang tanpa resep. Isi Harga
                Non Lapak-nya di sini; nama, harga lapak, dan HPP diatur tim menu di aplikasi Menu (Master Menu).
            </p>
        </div>
        <div class="toolbar-actions">
            @if ($priceMissingCount > 0 || $priceMissing)
                <a href="{{ $priceMissing ? route('adminapp.products.index') : route('adminapp.products.index', ['harga' => 'kosong']) }}"
                    class="{{ $priceMissing ? 'btn-primary' : 'btn-gray' }}">
                    {{ $priceMissing ? 'Tampilkan semua menu' : 'Harga belum diisi ('.number_format($priceMissingCount, 0, ',', '.').')' }}
                </a>
            @endif

            <a href="{{ route('adminapp.products.export.excel') }}" class="btn-gray">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2">
                    <path d="M12 3v12" />
                    <path d="m7 10 5 5 5-5" />
                    <path d="M5 21h14" />
                </svg>
                <span>Export Excel</span>
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-success mt-4">{{ session('status') }}</div>
    @endif

    <section class="table-shell mt-4">
        <x-table-toolbar title="Daftar Menu" :action="route('adminapp.products.index')"
            :meta="number_format($products->total(), 0, ',', '.').' menu'.(filled($q ?? null) ? ' cocok' : '')"
            search="q" :search-value="$q ?? ''" search-placeholder="Cari menu (nama / SKU)..." live />
        <div class="products-wrap">
            <table class="data-table products-table" id="js-products-table">
                <thead>
                    <tr>
                        <th>Nama Menu</th>
                        <th>Satuan</th>
                        <th class="text-right">Modal <span class="text-[10px] font-normal text-slate-400">(BB / OHC)</span></th>
                        <th class="text-right">Profit</th>
                        <th class="text-right">Margin</th>
                        <th class="text-right">Harga</th>
                        <th>Kategori</th>
                        <th>Aktif</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        @php
                            $profitPrice = $product->profitPrice();
                            $productProfit = (float) ($product->profit ?? 0);
                            $totalCost = (float) ($product->raw_material_cost ?? 0) + (float) ($product->overhead_cost ?? 0);
                            $profitPercent = $totalCost > 0 ? ($productProfit / $totalCost) * 100 : null;
                            $marginPercent = $profitPrice > 0 ? ($productProfit / $profitPrice) * 100 : null;
                            $profitLow = $profitPercent !== null && $profitPercent < 25;
                            $marginLow = $marginPercent !== null && $marginPercent < 25;
                            $profitColor = $profitPercent === null
                                ? 'text-slate-500'
                                : ($profitLow ? 'text-rose-600' : 'text-emerald-600');
                            $marginColor = $marginPercent === null
                                ? 'text-slate-500'
                                : ($marginLow ? 'text-rose-600' : 'text-emerald-600');
                            $anyLow = $profitLow || $marginLow;
                        @endphp
                        <tr data-search-name="{{ strtolower($product->name) }}">
                            <td class="name-cell">
                                <div class="font-semibold text-slate-800">{{ $product->name }}</div>
                                @if (filled($product->sku))
                                    <div class="text-[10px] font-mono text-slate-400">{{ $product->sku }}</div>
                                @endif
                                @if ($anyLow)
                                    <span class="mt-1 badge-soft-rose"
                                          title="Profit atau margin di bawah 25% — perlu review harga atau biaya">
                                        Margin rendah
                                    </span>
                                @endif
                                {{-- Tautan ke resep aplikasi Menu: produk yang belum dicocokkan tidak ikut HPP resep & SPK Produksi. --}}
                                @if ($product->recipe)
                                    <div class="mt-1 text-[11px] text-slate-500" title="Resep di aplikasi Menu">
                                        Resep: <span class="font-medium text-slate-700">{{ $product->recipe->name }}</span>
                                        @if ($product->costFollowsRecipe())
                                            <span class="cost-tag is-recipe" title="HPP &amp; OHC mengikuti resep (diatur di aplikasi Menu)">HPP dari resep</span>
                                        @else
                                            <span class="cost-tag is-manual" title="Hitungan resep belum lengkap; HPP &amp; OHC masih angka manual">HPP manual</span>
                                        @endif
                                    </div>
                                @else
                                    {{-- Daftar ini hanya menu yang bisa dipesan: tanpa resep = ditandai memang tanpa resep. --}}
                                    <div class="mt-1 text-[11px] text-slate-400">Memang tanpa resep</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">{{ $product->unit }}</td>
                            <td class="text-right tabular-nums whitespace-nowrap">
                                <div>
                                    @if($product->raw_material_cost !== null)
                                        <span class="rp">Rp</span>{{ number_format($product->raw_material_cost, 0, ',', '.') }}
                                    @else
                                        -
                                    @endif
                                </div>
                                <div class="text-slate-500">
                                    @if($product->overhead_cost !== null)
                                        <span class="rp">Rp</span>{{ number_format($product->overhead_cost, 0, ',', '.') }}
                                    @else
                                        -
                                    @endif
                                </div>
                            </td>
                            <td class="text-right tabular-nums whitespace-nowrap">
                                <div class="font-semibold {{ $profitColor }}">
                                    <span class="rp">Rp</span>{{ number_format($productProfit, 0, ',', '.') }}
                                </div>
                                <div class="text-[11px] font-medium {{ $profitColor }}">
                                    {{ $profitPercent !== null ? number_format($profitPercent, 2, ',', '.') . '%' : '-' }}
                                </div>
                            </td>
                            <td class="text-right tabular-nums whitespace-nowrap">
                                <div class="font-semibold {{ $marginColor }}">
                                    {{ $marginPercent !== null ? number_format($marginPercent, 2, ',', '.') . '%' : '-' }}
                                </div>
                            </td>
                            <td class="text-right tabular-nums whitespace-nowrap">
                                @if ($product->lapak_price !== null)
                                    <div class="font-semibold text-amber-700">
                                        <span class="text-[10px]">Lapak</span> <span class="rp">Rp</span>{{ number_format($product->lapak_price, 0, ',', '.') }}
                                        @if ($product->lapakFollowsRecipe())
                                            <span class="text-[10px]" title="Dari Harga Jual Target resep (aplikasi Menu)">· resep</span>
                                        @endif
                                    </div>
                                @endif
                                <div class="text-xs text-slate-600"><span class="text-[10px]">Non Lapak</span> <span class="rp">Rp</span>{{ number_format($product->base_price, 0, ',', '.') }}</div>
                                @if ((float) $product->base_price <= 0)
                                    <span class="mt-1 badge-soft-amber" title="Menu ini belum punya Harga Non Lapak">Harga belum diisi</span>
                                @endif
                                @if (!empty($product->price_last_changed_at) && ($product->price_histories_count ?? 0) > 1)
                                    @php
                                        $lastChangedAt = \Carbon\Carbon::parse($product->price_last_changed_at);
                                    @endphp
                                    <div class="text-[10px] text-slate-500"
                                        title="Harga terakhir berubah {{ $lastChangedAt->format('d M Y H:i') }}">
                                        {{ $lastChangedAt->diffForHumans() }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if ($product->is_3s)
                                    <span class="chip bg-amber-100 text-amber-800">Menu 3S</span>
                                @else
                                    <span class="chip bg-slate-100 text-slate-600">Retail</span>
                                @endif
                            </td>
                            <td>
                                @if ($product->active)
                                    <span class="chip bg-emerald-100 text-emerald-700">Aktif</span>
                                @else
                                    <span class="chip bg-slate-200 text-slate-700">Nonaktif</span>
                                @endif
                            </td>
                            <td>
                                <x-row-action kind="edit" :href="route('adminapp.products.edit', $product)" label="Isi harga" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-8 text-center text-sm text-slate-500">
                                @if (!empty($q))
                                    Tidak ada menu yang cocok dengan "<strong>{{ $q }}</strong>".
                                @else
                                    Belum ada data menu.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 text-[10px] text-slate-500 border-t border-slate-100">
            <strong>Modal</strong>: baris atas = Bahan Baku, baris bawah (redup) = Overhead Cost (OHC) &nbsp;·&nbsp;
            <strong>Profit %</strong> = Profit / (BB+OHC) &nbsp;·&nbsp;
            <strong>Profit</strong> &amp; <strong>Margin</strong> dihitung dari Harga Lapak (kosong = Harga Non Lapak) &nbsp;·&nbsp;
            <strong>Margin</strong> = Profit / Harga Lapak &nbsp;·&nbsp;
            Merah jika &lt; 25%.
        </div>
    </section>

    @if (method_exists($products, 'links'))
        <div class="mt-4">{{ $products->links() }}</div>
    @endif

@endsection
