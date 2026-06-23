@extends('layouts.adminapp')

@section('title', 'Master Menu')

@push('styles')
<style>
    .products-wrap {
        overflow: hidden;
    }
    .products-table {
        width: 100%;
        table-layout: auto;
    }
    .products-table th,
    .products-table td {
        padding: 0.45rem 0.5rem;
        font-size: 12px;
        line-height: 1.35;
    }
    .products-table thead th {
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        color: rgb(71 85 105);
        background: rgb(248 250 252);
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
    @media (max-width: 1100px) {
        .products-table {
            font-size: 11px;
        }
        .products-table th,
        .products-table td {
            padding: 0.35rem 0.4rem;
        }
    }
</style>
@endpush

@section('content')
    <div class="page-toolbar">
        <div>
            <h1 class="section-title">Master Menu</h1>
            <p class="section-subtitle">Kelola data produk/menu yang digunakan pada purchase order.</p>
        </div>
        <div class="toolbar-actions">
            <a href="{{ route('adminapp.products.create') }}" class="btn-primary">
                + Tambah Menu Baru
            </a>

            <a href="{{ route('adminapp.products.export.excel') }}" class="btn-success gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2">
                    <path d="M12 3v12" />
                    <path d="m7 10 5 5 5-5" />
                    <path d="M5 21h14" />
                </svg>
                <span>Export Excel</span>
            </a>

            <form method="POST" action="{{ route('adminapp.products.import.excel') }}" enctype="multipart/form-data"
                class="toolbar-inline-form">
                @csrf
                <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" class="file-input" required>
                <button type="submit" class="btn-outline gap-2 whitespace-nowrap">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2">
                        <path d="M12 21V9" />
                        <path d="m17 14-5-5-5 5" />
                        <path d="M5 3h14" />
                    </svg>
                    <span>Import Excel</span>
                </button>
            </form>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-success mt-4">{{ session('status') }}</div>
    @endif

    @if (!empty($importPreview))
        @php
            $previewRows = $importPreview['rows'] ?? [];
            $previewSummary = $importPreview['summary'] ?? ['created' => 0, 'updated' => 0, 'total' => 0];
            $visiblePreviewRows = array_slice($previewRows, 0, 100);
        @endphp

        <section class="table-shell mt-4">
            <div class="table-head">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div>Preview Import: {{ $importPreview['file_name'] ?? 'Excel' }}</div>
                        <div class="mt-1 text-xs font-medium normal-case tracking-normal text-slate-500">
                            {{ $previewSummary['created'] }} data baru, {{ $previewSummary['updated'] }} data update, total
                            {{ $previewSummary['total'] }} baris. Data belum masuk database sebelum commit.
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('adminapp.products.import.excel.commit') }}">
                            @csrf
                            <button type="submit" class="btn-success whitespace-nowrap">Commit Import</button>
                        </form>
                        <form method="POST" action="{{ route('adminapp.products.import.excel.cancel') }}">
                            @csrf
                            <button type="submit" class="btn-outline whitespace-nowrap">Batalkan</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Baris</th>
                            <th>Aksi</th>
                            <th>ID Match</th>
                            <th>SKU</th>
                            <th>Nama Menu</th>
                            <th>Satuan</th>
                            <th class="text-right">Bahan Baku</th>
                            <th class="text-right">Overhead</th>
                            <th class="text-right">Profit Baru</th>
                            <th class="text-right">Harga Jual</th>
                            <th>Kategori</th>
                            <th>Aktif</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($visiblePreviewRows as $row)
                            @php
                                $data = $row['data'];
                                $isUpdate = ($row['action'] ?? null) === 'update';
                            @endphp
                            <tr>
                                <td>{{ $row['excel_row'] }}</td>
                                <td>
                                    @if ($isUpdate)
                                        <span class="chip bg-sky-100 text-sky-700">Update</span>
                                    @else
                                        <span class="chip bg-emerald-100 text-emerald-700">Tambah Baru</span>
                                    @endif
                                </td>
                                <td>{{ $row['matched_id'] ?? '-' }}</td>
                                <td class="font-mono text-xs">{{ $data['sku'] ?? '-' }}</td>
                                <td class="font-semibold text-slate-800">{{ $data['name'] }}</td>
                                <td>{{ $data['unit'] }}</td>
                                <td class="text-right">
                                    {{ $data['raw_material_cost'] !== null ? 'Rp ' . number_format($data['raw_material_cost'], 0, ',', '.') : '-' }}
                                </td>
                                <td class="text-right">
                                    {{ $data['overhead_cost'] !== null ? 'Rp ' . number_format($data['overhead_cost'], 0, ',', '.') : '-' }}
                                </td>
                                <td class="text-right">Rp {{ number_format($row['profit'] ?? 0, 0, ',', '.') }}</td>
                                <td class="text-right">Rp {{ number_format($data['base_price'], 0, ',', '.') }}</td>
                                <td>
                                    @if (! empty($data['is_3s']))
                                        <span class="chip bg-amber-100 text-amber-800">Menu 3S</span>
                                    @else
                                        <span class="chip bg-slate-100 text-slate-600">Retail</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($data['active'] === null)
                                        -
                                    @elseif($data['active'])
                                        <span class="chip bg-emerald-100 text-emerald-700">Aktif</span>
                                    @else
                                        <span class="chip bg-slate-200 text-slate-700">Nonaktif</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        @if (count($previewRows) > count($visiblePreviewRows))
                            <tr>
                                <td colspan="12" class="py-4 text-center text-sm text-slate-500">
                                    {{ count($previewRows) - count($visiblePreviewRows) }} baris lainnya disembunyikan dari
                                    preview.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="table-shell mt-4">
        <div class="table-head">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <span>Daftar Menu</span>
                <form method="GET" action="{{ route('adminapp.products.index') }}"
                      class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                    <input type="search" name="q" id="js-products-search" class="form-control sm:w-64"
                        value="{{ $q ?? '' }}"
                        placeholder="Cari menu (nama / SKU)..." autocomplete="off">
                    @if (!empty($q))
                        <a href="{{ route('adminapp.products.index') }}"
                           class="text-xs font-semibold text-slate-500 hover:text-slate-700">Reset</a>
                    @endif
                    <span class="text-xs font-medium normal-case tracking-normal text-slate-500">
                        {{ $products->total() }} menu
                        @if (!empty($q)) cocok untuk "<strong>{{ $q }}</strong>" @else total @endif.
                    </span>
                </form>
            </div>
        </div>
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
                            $basePrice = (float) ($product->base_price ?? 0);
                            $productProfit = (float) ($product->profit ?? 0);
                            $totalCost = (float) ($product->raw_material_cost ?? 0) + (float) ($product->overhead_cost ?? 0);
                            $profitPercent = $totalCost > 0 ? ($productProfit / $totalCost) * 100 : null;
                            $marginPercent = $basePrice > 0 ? ($productProfit / $basePrice) * 100 : null;
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
                                    <span class="mt-1 inline-block rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-700"
                                          title="Profit atau margin di bawah 25% — perlu review harga atau biaya">
                                        Margin rendah
                                    </span>
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
                                <div><span class="rp">Rp</span>{{ number_format($product->base_price, 0, ',', '.') }}</div>
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
                                <a href="{{ route('adminapp.products.edit', $product) }}"
                                    class="text-sm font-semibold text-brand-600 hover:text-brand-500">
                                    Edit
                                </a>
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
            <strong>Margin</strong> = Profit / Harga &nbsp;·&nbsp;
            Merah jika &lt; 25%.
        </div>
    </section>

    @if (method_exists($products, 'links'))
        <div class="mt-4">{{ $products->links() }}</div>
    @endif

    <script>
        (() => {
            const input = document.getElementById('js-products-search');
            if (!input) return;
            const form = input.closest('form');
            if (!form) return;

            let timer = null;
            input.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => form.submit(), 400);
            });
        })();
    </script>
@endsection
