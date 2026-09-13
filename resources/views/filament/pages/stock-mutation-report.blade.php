<x-filament-panels::page>
    {{ $this->form }}

    @php
        $summary = $this->getSummary();
        $rows = $this->getDetailRows();
        $item = $this->getSelectedItem();
    @endphp

    @if ($summary)
        <div class="grid gap-4 md:grid-cols-5">
            @foreach ([
                ['Bahan Baku Lama', $summary['opening'] ?? 0, null],
                ['Pembelian', $summary['purchases'] ?? 0, 'Barang berkondisi tidak baik tidak dihitung.'],
                ['Sisa Stok', $summary['ending'] ?? 0, null],
                ['Pemakaian', $summary['usage'] ?? 0, null],
                ['Pemakaian Resep', $summary['usage_recipe'] ?? 0, 'Dari SPK Produksi yang ditutup di periode ini; pembanding residual.'],
            ] as [$label, $value, $note])
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div @class([
                        'mt-1 text-xl font-semibold',
                        'text-amber-600 dark:text-amber-400' => $label === 'Pemakaian',
                    ])>
                        Rp {{ number_format((float) $value, 2, ',', '.') }}
                    </div>
                    @if ($note)
                        <div class="mt-1 text-xs text-gray-400">{{ $note }}</div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section :heading="'Rincian Mutasi ' . ($item?->name ?? '-') . ($item?->unit ? ' (' . $item->unit . ')' : '')">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                            <th class="py-2 pr-4 font-medium">Tanggal</th>
                            <th class="py-2 pr-4 font-medium">Jenis</th>
                            <th class="py-2 pr-4 text-right font-medium">Nilai</th>
                            <th class="py-2 font-medium">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr @class([
                                'border-b border-gray-100 dark:border-gray-800',
                                'bg-gray-50 font-semibold dark:bg-white/5' => ($row['kind'] ?? null) === 'subtotal',
                                'bg-amber-50 font-bold dark:bg-amber-500/10' => ($row['kind'] ?? null) === 'result',
                            ])>
                                @if (($row['kind'] ?? null) === 'entry')
                                    <td class="py-2 pr-4">{{ $row['date_text'] ?? '-' }}</td>
                                    <td class="py-2 pr-4">{{ $row['type'] ?? '-' }}</td>
                                @else
                                    <td class="py-2 pr-4" colspan="2">{{ $row['label'] ?? '' }}</td>
                                @endif
                                <td class="py-2 pr-4 text-right">
                                    Rp {{ number_format((float) ($row['value'] ?? 0), 2, ',', '.') }}
                                </td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ $row['notes'] ?? '' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-4 text-center text-gray-500">
                                    Belum ada mutasi pada rentang tanggal ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Pilih item lebih dulu untuk menampilkan mutasi stoknya.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
