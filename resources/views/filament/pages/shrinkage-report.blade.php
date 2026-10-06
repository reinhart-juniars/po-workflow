@php
    $rows = $this->getRows();
    $detail = $this->getDetail();
    $thresholds = $this->getThresholds();
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    $rupiah = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $pct = fn ($v) => $v === null ? '-' : number_format((float) $v, 1, ',', '.') . '%';
    $tgl = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '-';
    $badgeColor = [
        \App\Services\InventoryShrinkageService::LEVEL_GREEN => 'success',
        \App\Services\InventoryShrinkageService::LEVEL_YELLOW => 'warning',
        \App\Services\InventoryShrinkageService::LEVEL_RED => 'danger',
        \App\Services\InventoryShrinkageService::LEVEL_NONE => 'gray',
    ];
    $labels = \App\Services\InventoryShrinkageService::levelLabels();
    $count = fn (string $level) => $rows->where('level', $level)->count();
    $green = number_format($thresholds['green'], 1, ',', '.');
    $yellow = number_format($thresholds['yellow'], 1, ',', '.');
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <div class="sh-kpi" style="--cols: 4">
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Merah</p>
            <p class="sh-kpi-value is-bad">{{ $count(\App\Services\InventoryShrinkageService::LEVEL_RED) }}</p>
            <p class="sh-kpi-note">susut di atas {{ $yellow }}% — telusuri</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Kuning</p>
            <p class="sh-kpi-value">{{ $count(\App\Services\InventoryShrinkageService::LEVEL_YELLOW) }}</p>
            <p class="sh-kpi-note">{{ $green }}% sampai {{ $yellow }}%</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Hijau</p>
            <p class="sh-kpi-value is-ok">{{ $count(\App\Services\InventoryShrinkageService::LEVEL_GREEN) }}</p>
            <p class="sh-kpi-note">di bawah {{ $green }}%</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Nilai susut</p>
            <p class="sh-kpi-value">{{ $rupiah($rows->sum('shrink_value')) }}</p>
            <p class="sh-kpi-note">Barang Hilang {{ $rupiah($rows->sum('loss_value')) }}, Temuan {{ $rupiah($rows->sum('found_value')) }}</p>
        </div>
    </div>

    <x-filament::section heading="Per bahan">
        <x-slot name="description">
            Susut = pemakaian lebih dari resep + Barang Hilang (hitung fisik kurang dari kartu stok) - Barang Temuan,
            dibagi kebutuhan resep. Hanya SPK Produksi yang sudah ditutup yang dihitung. Klik bahan untuk melihat SPK
            dan menu yang memakainya. Batas warna diubah di Pengaturan Inventory.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr>
                        <th>Bahan</th>
                        <th class="text-right">Kebutuhan Resep</th>
                        <th class="text-right">Pemakaian</th>
                        <th class="text-right">Lebih Pakai</th>
                        <th class="text-right">Barang Hilang</th>
                        <th class="text-right">Barang Temuan</th>
                        <th class="text-right">Susut</th>
                        <th>Indikator</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="susut-{{ $row['inventory_item_id'] }}" wire:click="selectItem({{ $row['inventory_item_id'] }})"
                            @class(['cursor-pointer', 'bg-primary-50 dark:bg-white/5' => $this->selectedItemId === $row['inventory_item_id']])>
                            <td class="font-medium">
                                {{ $row['name'] }}
                                <div class="text-xs text-gray-500">{{ $row['bucket'] ?? '-' }} &middot; {{ $row['unit'] }}</div>
                            </td>
                            <td class="text-right">{{ $qty($row['recipe_qty']) }}</td>
                            <td class="text-right">{{ $qty($row['usage_qty']) }}</td>
                            <td class="text-right">{{ $qty($row['over_usage_qty']) }}</td>
                            <td class="text-right">
                                {{ $qty($row['loss_qty']) }}
                                @if ($row['loss_value'] > 0)
                                    <div class="text-xs text-gray-500">{{ $rupiah($row['loss_value']) }}</div>
                                @endif
                            </td>
                            <td class="text-right">
                                {{ $qty($row['found_qty']) }}
                                @if ($row['found_value'] > 0)
                                    <div class="text-xs text-gray-500">{{ $rupiah($row['found_value']) }}</div>
                                @endif
                            </td>
                            <td class="text-right font-semibold">
                                {{ $pct($row['shrink_pct']) }}
                                <div class="text-xs font-normal text-gray-500">{{ $qty($row['shrink_qty']) }} {{ $row['unit'] }} &middot; {{ $rupiah($row['shrink_value']) }}</div>
                            </td>
                            <td>
                                <x-filament::badge :color="$badgeColor[$row['level']] ?? 'gray'">{{ $labels[$row['level']] ?? $row['level'] }}</x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-6 text-center text-gray-500">Belum ada SPK Produksi yang ditutup atau opname bahan di rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @if ($detail)
        <x-filament::section :heading="'Ke mana susut ' . $detail['item']->name . ' pergi'">
            <x-slot name="description">
                Per SPK: selisih pemakaian terhadap resep dan hasil hitung sisa stok. Bagian susut per menu dibagi menurut
                porsi kebutuhan resepnya (perkiraan — dapur mencatat pemakaian per bahan, bukan per menu).
            </x-slot>

            @forelse ($detail['orders'] as $order)
                <div class="mb-6">
                    <p class="font-semibold">
                        <a class="text-primary-600 hover:underline" href="{{ route('filament.admin.resources.production-orders.edit', $order['production_order_id']) }}">{{ $order['number'] }}</a>
                        <span class="font-normal text-gray-500">&middot; {{ $tgl($order['production_date']) }}</span>
                    </p>
                    <p class="text-sm text-gray-600">
                        Resep {{ $qty($order['recipe_qty']) }},
                        aktual {{ $order['actual_qty'] === null ? 'tidak dicatat' : $qty($order['actual_qty']) }},
                        hilang {{ $qty($order['loss_qty']) }}, temuan {{ $qty($order['found_qty']) }}
                        &rarr; <span class="font-semibold">susut {{ $qty($order['shrink_qty']) }} {{ $detail['item']->unit }}</span>
                    </p>

                    <table class="sh-table mt-2">
                        <thead>
                            <tr>
                                <th>Menu</th>
                                <th class="text-right">Porsi</th>
                                <th class="text-right">Kebutuhan Bahan</th>
                                <th class="text-right">Bagian</th>
                                <th class="text-right">Bagian Susut</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($order['menus'] as $menu)
                                <tr>
                                    <td>{{ $menu['label'] }}</td>
                                    <td class="text-right">{{ $qty($menu['porsi']) }} {{ $menu['unit'] }}</td>
                                    <td class="text-right">{{ $qty($menu['qty']) }}</td>
                                    <td class="text-right">{{ number_format($menu['share_pct'], 1, ',', '.') }}%</td>
                                    <td class="text-right">{{ $qty($menu['shrink_qty']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-gray-500">Tidak ada menu berresep di SPK ini yang memakai bahan ini (bahan ditambahkan manual ke form kebutuhan).</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @empty
                <p class="text-sm text-gray-500">Bahan ini tidak dipakai SPK Produksi yang ditutup di rentang ini.</p>
            @endforelse

            @if ($detail['standalone'] !== [])
                <p class="mt-4 font-semibold">Dari Opname Bahan</p>
                <table class="sh-table mt-2">
                    <thead>
                        <tr>
                            <th>Keterangan</th>
                            <th>Tanggal</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($detail['standalone'] as $row)
                            <tr>
                                <td>{{ $row['qty'] < 0 ? 'Barang Hilang' : 'Barang Temuan' }}{{ $row['notes'] ? ' — ' . $row['notes'] : '' }}</td>
                                <td>{{ $tgl($row['date']) }}</td>
                                <td class="text-right">{{ $qty($row['qty']) }}</td>
                                <td class="text-right">{{ $rupiah($row['value']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
