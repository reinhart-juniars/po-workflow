@php
    $rows = $this->getRows();
    $rupiah = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $tgl = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : null;
    $punyaResep = $rows->where('has_recipe', true)->count();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <div class="sh-kpi" style="--cols: 3">
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Menu tidak diproduksi</p>
            <p class="sh-kpi-value">{{ number_format($rows->count(), 0, ',', '.') }}</p>
            <p class="sh-kpi-note">dalam rentang yang dipilih</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Punya resep</p>
            <p class="sh-kpi-value">{{ number_format($punyaResep, 0, ',', '.') }}</p>
            <p class="sh-kpi-note">bisa diproduksi tetapi tidak dipesan</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Belum punya resep</p>
            <p class="sh-kpi-value">{{ number_format($rows->count() - $punyaResep, 0, ',', '.') }}</p>
            <p class="sh-kpi-note">tidak akan pernah muncul di SPK Produksi</p>
        </div>
    </div>

    <x-filament::section heading="Daftar menu">
        <x-slot name="description">
            Menu yang tidak muncul di SPK Produksi mana pun (kecuali yang dibatalkan) di rentang ini.
            "Terakhir terjual" dibaca dari sales actual, supaya menu yang laku tetapi belum dipetakan ke resep terlihat.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr>
                        <th>Menu</th>
                        <th>SKU</th>
                        <th class="text-right">Harga Jual</th>
                        <th>Resep</th>
                        <th>Terakhir Diproduksi</th>
                        <th>Terakhir Terjual</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="font-medium">{{ $row['name'] }}</td>
                            <td class="text-gray-500">{{ $row['sku'] ?: '-' }}</td>
                            <td class="text-right">{{ $rupiah($row['base_price']) }}</td>
                            <td>{{ $row['has_recipe'] ? 'Ada' : 'Belum' }}</td>
                            <td>{{ $tgl($row['last_produced']) ?? 'Belum pernah' }}</td>
                            <td>{{ $tgl($row['last_sold']) ?? 'Belum pernah' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">Semua menu aktif diproduksi dalam rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
