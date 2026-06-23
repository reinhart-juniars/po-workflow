@extends('layouts.adminapp')

@section('title', $mode === 'create' ? 'Tambah Menu' : 'Edit Menu')

@section('content')
    <div class="page-toolbar">
        <div>
            <h1 class="section-title">{{ $mode === 'create' ? 'Tambah Menu Baru' : 'Edit Menu' }}</h1>
            <p class="section-subtitle">Lengkapi informasi menu agar dapat digunakan pada order.</p>
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

    <form method="POST"
        action="{{ $mode === 'create' ? route('adminapp.products.store') : route('adminapp.products.update', $product) }}"
        class="form-shell mt-4 space-y-5">
        @csrf
        @if ($mode === 'edit')
            @method('PUT')
        @endif

        <div class="form-grid">
            <div>
                <label for="sku" class="mb-1.5 block">SKU</label>
                <input id="sku" type="text" value="{{ old('sku', $product->sku) }}" readonly
                    class="bg-slate-100 text-slate-500 cursor-not-allowed">
                <p class="mt-1 text-xs text-slate-500">SKU dibuat otomatis dari nama menu saat simpan.</p>
            </div>

            <div>
                <label for="name" class="mb-1.5 block">Nama Menu</label>
                <input id="name" type="text" name="name" value="{{ old('name', $product->name) }}" required
                    autofocus>
            </div>

            <div class="md:col-span-2">
                <label for="unit" class="mb-1.5 block">Satuan</label>
                <input id="unit" type="text" name="unit" value="{{ old('unit', $product->unit ?? 'PORSI') }}"
                    required>
            </div>

            <div class="md:col-span-2">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <label for="raw_material_cost" class="mb-1.5 block">Bahan Baku</label>
                        <input id="raw_material_cost" type="number" step="0.01" min="0" name="raw_material_cost"
                            value="{{ old('raw_material_cost', $product->raw_material_cost) }}">
                        <p class="mt-1 text-xs text-slate-500">Kosongkan jika belum ada data biaya bahan baku.</p>
                    </div>

                    <div>
                        <label for="overhead_cost" class="mb-1.5 block">Overhead Cost</label>
                        <input id="overhead_cost" type="number" step="0.01" min="0" name="overhead_cost"
                            value="{{ old('overhead_cost', $product->overhead_cost) }}">
                        <p class="mt-1 text-xs text-slate-500">Isi biaya operasional tambahan per menu bila ada.</p>
                    </div>

                    <div>
                        <label for="profit" class="mb-1.5 block">Profit</label>
                        <input id="profit" type="number" step="0.01" value="{{ old('profit', $product->profit) }}"
                            disabled class="bg-slate-100 text-slate-500 cursor-not-allowed">
                        <div id="profit_subtitle" class="mt-1 space-y-0.5 text-xs font-semibold">
                            <p>
                                Profit =
                                <span id="profit_percent" class="text-slate-500">-</span>
                            </p>
                            <p>
                                Margin =
                                <span id="margin_percent" class="text-slate-500">-</span>
                            </p>
                        </div>
                    </div>

                    <div>
                        <label for="base_price" class="mb-1.5 block">Harga Jual</label>
                        <input id="base_price" type="number" step="0.01" min="0" name="base_price"
                            value="{{ old('base_price', $product->base_price) }}" required>
                        <p class="mt-1 text-xs text-slate-500">Profit otomatis = harga jual - (bahan baku + overhead).</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-6">
            <label for="active" class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                <input id="active" type="checkbox" name="active" value="1" @checked(old('active', $product->active ?? true))
                    class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500/30">
                Aktif
            </label>

            <label for="is_3s" class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                <input id="is_3s" type="checkbox" name="is_3s" value="1" @checked(old('is_3s', $product->is_3s ?? false))
                    class="h-4 w-4 rounded border-slate-300 text-amber-500 focus:ring-amber-500/30">
                Menu 3S
                <span class="text-xs font-normal text-slate-500">(Menu Premium)</span>
            </label>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="submit" class="btn-primary">Simpan</button>
            <a href="{{ route('adminapp.products.index') }}" class="btn-ghost">Batal</a>
        </div>
    </form>

    @if ($mode === 'edit' && $product->relationLoaded('priceHistories') && $product->priceHistories->isNotEmpty())
        <section class="form-shell mt-6 space-y-3">
            <div>
                <h2 class="section-title text-base">Riwayat Harga</h2>
                <p class="section-subtitle">
                    Semua perubahan harga jual & cost tercatat di sini. Transaksi PO/penjualan lama tetap pakai snapshot
                    harga saat itu, jadi mengubah harga master tidak akan mengubah laporan periode sebelumnya.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                            <th class="px-3 py-2">Berlaku Sejak</th>
                            <th class="px-3 py-2 text-right">Bahan Baku</th>
                            <th class="px-3 py-2 text-right">Overhead</th>
                            <th class="px-3 py-2 text-right">Profit</th>
                            <th class="px-3 py-2 text-right">Harga Jual</th>
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
                                                class="ml-1 inline-flex items-center rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-700">
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

    <script>
        (() => {
            const mode = @json($mode);
            const nameInput = document.getElementById('name');
            const skuInput = document.getElementById('sku');
            const basePriceInput = document.getElementById('base_price');
            const rawMaterialInput = document.getElementById('raw_material_cost');
            const overheadInput = document.getElementById('overhead_cost');
            const profitInput = document.getElementById('profit');
            const profitPercentText = document.getElementById('profit_percent');
            const marginPercentText = document.getElementById('margin_percent');

            const buildSku = (value) => {
                const normalized = value
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .toUpperCase()
                    .replace(/[^A-Z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '')
                    .slice(0, 50);

                return normalized || 'MENU';
            };

            const toNumber = (value) => {
                const parsed = Number.parseFloat(value || '0');

                return Number.isFinite(parsed) ? parsed : 0;
            };

            const syncSku = () => {
                skuInput.value = buildSku(nameInput.value);
            };

            const formatPercent = (value) => {
                return `${value.toLocaleString('id-ID', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })}%`;
            };

            const syncPercentText = (element, value) => {
                if (!element) {
                    return;
                }

                element.classList.remove('text-emerald-600', 'text-rose-600', 'text-slate-500');

                if (value === null) {
                    element.textContent = '-';
                    element.classList.add('text-slate-500');
                    return;
                }

                element.textContent = formatPercent(value);
                element.classList.add(value >= 25 ? 'text-emerald-600' : 'text-rose-600');
            };

            const syncProfit = () => {
                if (!profitInput || !basePriceInput) {
                    return;
                }

                if ((basePriceInput.value || '').trim() === '') {
                    profitInput.value = '';
                    syncPercentText(profitPercentText, null);
                    syncPercentText(marginPercentText, null);
                    return;
                }

                const basePrice = toNumber(basePriceInput.value);
                const totalCost = toNumber(rawMaterialInput?.value) + toNumber(overheadInput?.value);
                const profit = basePrice - totalCost;
                const profitPercent = totalCost > 0 ? (profit / totalCost) * 100 : null;
                const marginPercent = basePrice > 0 ? (profit / basePrice) * 100 : null;

                profitInput.value = profit.toFixed(2);
                syncPercentText(profitPercentText, profitPercent);
                syncPercentText(marginPercentText, marginPercent);
            };

            if (nameInput && skuInput && mode === 'create') {
                nameInput.addEventListener('input', syncSku);
                syncSku();
            }

            [basePriceInput, rawMaterialInput, overheadInput].forEach((input) => {
                input?.addEventListener('input', syncProfit);
            });

            syncProfit();
        })();
    </script>
@endsection
