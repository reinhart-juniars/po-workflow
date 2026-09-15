@php
    $rows = $this->getRows();
    $rupiah = fn ($v) => 'Rp ' . number_format((float) $v, 2, ',', '.');
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section heading="Per bucket stok">
        <x-slot name="description">
            Residual = Bahan Baku Lama + Pembelian − Sisa Stok (cara lama, dari nilai rupiah per bucket).
            Resep = jumlah pemakaian bahan yang diposting saat SPK Produksi ditutup, ditambah penyesuaian ke sisa fisik.
            Selisih positif berarti residual lebih besar daripada yang bisa dijelaskan resep.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                        <th class="py-2 pr-4 font-medium">Bucket</th>
                        <th class="py-2 pr-4 text-right font-medium">Residual (Opname)</th>
                        <th class="py-2 pr-4 text-right font-medium">Pemakaian Resep</th>
                        <th class="py-2 pr-4 text-right font-medium">Penyesuaian</th>
                        <th class="py-2 pr-4 text-right font-medium">Total Resep</th>
                        <th class="py-2 pr-4 text-right font-medium">Selisih</th>
                        <th class="py-2 text-right font-medium">Gerakan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4 font-medium">
                                {{ $row['bucket'] }}
                                @if ($row['residual_source'] === 'zero')
                                    <div class="text-xs text-warning-600">Belum ada opname penutup di periode ini; residual belum final.</div>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right">{{ $rupiah($row['residual']) }}</td>
                            <td class="py-2 pr-4 text-right">{{ $rupiah($row['recipe_usage']) }}</td>
                            <td class="py-2 pr-4 text-right">{{ $rupiah($row['recipe_adjustment']) }}</td>
                            <td class="py-2 pr-4 text-right font-semibold">{{ $rupiah($row['recipe_total']) }}</td>
                            <td @class([
                                'py-2 pr-4 text-right font-semibold',
                                'text-danger-600 dark:text-danger-400' => abs($row['variance']) >= 0.005,
                                'text-success-600 dark:text-success-400' => abs($row['variance']) < 0.005,
                            ])>
                                {{ $rupiah($row['variance']) }}
                                @if ($row['variance_pct'] !== null)
                                    <div class="text-xs font-normal text-gray-500">{{ number_format($row['variance_pct'] * 100, 1, ',', '.') }}%</div>
                                @endif
                            </td>
                            <td class="py-2 text-right text-gray-500">{{ $row['movements'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">Tidak ada bucket stok, atau rentang tanggal tidak valid.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
