@php
    $recipe = $this->getRecipe();
    $cost = $this->getCost();
    $requirements = $this->getRequirements();
    $rupiah = fn ($value) => 'Rp ' . number_format((float) $value, 2, ',', '.');
    $angka = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
@endphp

<x-filament-panels::page>
    {{-- Angka dulu, baru masalahnya: strip KPI satu baris (sh-kpi di shell.css)
         karena CSS Filament tidak memuat md:grid-cols-* aplikasi. --}}
    <div class="sh-kpi" style="--cols: 4">
        @foreach ([
            ['HPP per ' . $recipe->yield_unit, $cost['hpp_per_yield'], 'Dari rincian bahan'],
            ['OHC', $cost['ohc'], round((float) $recipe->ohc_pct * 100, 2) . '% dari HPP'],
            ['Profit Hitungan', $cost['profit'], round((float) $recipe->profit_pct * 100, 2) . '% dari HPP + OHC'],
            ['Harga Jual', $cost['harga_jual_dipakai'], $cost['pakai_target'] ? 'Memakai harga target' : 'Hasil hitungan'],
        ] as [$label, $value, $note])
            <div class="sh-kpi-cell">
                <p class="sh-kpi-label">{{ $label }}</p>
                <p class="sh-kpi-value">{{ $rupiah($value) }}</p>
                <p class="sh-kpi-note">{{ $note }}</p>
            </div>
        @endforeach
    </div>

    <div class="sh-kpi" style="--cols: 3">
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Total Biaya (HPP + OHC)</p>
            <p class="sh-kpi-value">{{ $rupiah($cost['total_biaya']) }}</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Profit Aktual</p>
            <p class="sh-kpi-value {{ $cost['profit_ok'] ? 'is-ok' : 'is-bad' }}">{{ $rupiah($cost['profit_aktual']) }}</p>
            <p class="sh-kpi-note">{{ number_format($cost['profit_pct_aktual'] * 100, 1, ',', '.') }}% dari biaya</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Margin terhadap Harga Jual</p>
            <p class="sh-kpi-value">{{ number_format($cost['margin_pct_aktual'] * 100, 1, ',', '.') }}%</p>
        </div>
    </div>

    @if ($cost['uses_snapshot'])
        <x-filament::section>
            <div class="text-sm text-warning-600 dark:text-warning-400">
                Resep ini belum punya rincian bahan. Angka di atas disalin dari Excel, bukan hasil
                perhitungan dari bahan — isi rincian bahannya agar HPP mengikuti harga terbaru.
            </div>
        </x-filament::section>
    @endif

    @if ($cost['issues'])
        {{-- Dilipat: daftarnya bisa belasan baris dan mendorong rincian ke bawah. --}}
        <x-filament::section
            :heading="count($cost['issues']) . ' baris menahan perhitungan'"
            description="Baris ini tidak ikut terhitung; selama belum beres, HPP di atas lebih kecil daripada biaya sebenarnya."
            icon="heroicon-m-exclamation-triangle"
            icon-color="danger"
            collapsible
            collapsed
        >
            <ul class="list-disc space-y-1 pl-5 text-sm text-danger-600 dark:text-danger-400">
                @foreach ($cost['issues'] as $issue)
                    <li>{{ $issue }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section :heading="'Rincian Biaya (' . count($cost['lines']) . ' baris)'">
        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                        <th class="py-2 pr-4 font-medium">Bahan / Sub-Menu</th>
                        <th class="py-2 pr-4 text-right font-medium">Jumlah</th>
                        <th class="py-2 pr-4 font-medium">Sumber</th>
                        <th class="py-2 pr-4 text-right font-medium">Harga Satuan</th>
                        <th class="py-2 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cost['lines'] as $line)
                        <tr @class([
                            'border-b border-gray-100 dark:border-gray-800',
                            'bg-danger-50 dark:bg-danger-500/10' => $line['issue'] !== null,
                        ])>
                            <td class="py-2 pr-4">
                                {{ $line['raw_name'] }}
                                @if ($line['issue'])
                                    <div class="mt-0.5 text-xs text-danger-600 dark:text-danger-400">
                                        {{ $line['issue'] }}
                                    </div>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">
                                {{ $angka($line['qty']) }} {{ $line['unit'] }}
                            </td>
                            <td class="py-2 pr-4">{{ $this->sourceLabel($line['source']) }}</td>
                            <td class="py-2 pr-4 text-right">
                                {{ $line['unit_price'] === null ? '-' : $rupiah($line['unit_price']) }}
                            </td>
                            <td class="py-2 text-right">{{ $rupiah($line['line_total']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                Resep ini belum punya rincian bahan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($cost['lines'])
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-2 pr-4" colspan="4">
                                Total satu kali produksi ({{ $angka($recipe->yield_qty) }} {{ $recipe->yield_unit }})
                            </td>
                            <td class="py-2 text-right">{{ $rupiah($cost['total_raw']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Kebutuhan Bahan">
        {{ $this->form }}

        @if ($requirements['issues'])
            <ul class="mt-4 list-disc space-y-1 pl-5 text-sm text-danger-600 dark:text-danger-400">
                @foreach ($requirements['issues'] as $issue)
                    <li>{{ $issue }}</li>
                @endforeach
            </ul>
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                        <th class="py-2 pr-4 font-medium">Bahan</th>
                        <th class="py-2 pr-4 text-right font-medium">Kebutuhan</th>
                        <th class="py-2 pr-4 text-right font-medium">Harga Satuan</th>
                        <th class="py-2 text-right font-medium">Biaya</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requirements['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4">{{ $row['name'] }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">
                                {{ $angka($row['qty']) }} {{ $row['unit'] }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ $row['unit_price'] === null ? '-' : $rupiah($row['unit_price']) }}
                            </td>
                            <td class="py-2 text-right">{{ $rupiah($row['total_cost']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                Isi jumlah produksi untuk melihat kebutuhan bahannya.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if (count($requirements['rows']))
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="py-2 pr-4" colspan="3">Total biaya bahan</td>
                            <td class="py-2 text-right">{{ $rupiah($requirements['total_cost']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
