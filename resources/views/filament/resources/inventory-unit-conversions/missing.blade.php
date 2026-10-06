<x-filament-panels::page>
    @php
        $summary = $this->getSummary();
        $rows = $this->getRows();
    @endphp

    <div class="sh-kpi" style="--cols: 4">
        @foreach ([
            ['Pasangan Satuan', $summary['pasangan'], 'Tiap pasangan butuh satu aturan'],
            ['Baris Resep Tertahan', $summary['baris'], 'Biayanya dihitung nol'],
            ['Punya Usulan Angka', $summary['usulan'], 'Dari nama bahan; tetap perlu dibenarkan'],
            ['Bahan Terdampak', $summary['bahan'], null],
        ] as [$label, $value, $note])
            <div class="sh-kpi-cell">
                <p class="sh-kpi-label">{{ $label }}</p>
                <p class="sh-kpi-value">{{ number_format((float) $value, 0, ',', '.') }}</p>
                @if ($note)
                    <p class="sh-kpi-note">{{ $note }}</p>
                @endif
            </div>
        @endforeach
    </div>

    <x-filament::section heading="Urut dari yang paling banyak menahan baris resep">
        <x-slot name="description">
            Resep menulis takaran, sementara harga bahannya tersimpan per kemasan. Satu aturan
            konversi membuka seluruh baris yang memakai pasangan satuan itu sekaligus.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                        <th class="py-2 pr-4 font-medium">Bahan</th>
                        <th class="py-2 pr-4 font-medium">Satuan Resep</th>
                        <th class="py-2 pr-4 font-medium">Satuan Harga</th>
                        <th class="py-2 pr-4 text-right font-medium">Baris</th>
                        <th class="py-2 pr-4 text-right font-medium">Resep</th>
                        <th class="py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4 font-medium">
                                {{ $row['item_name'] }}
                                @if ($hint = $this->hintText($row))
                                    <div class="mt-0.5 text-xs font-normal text-primary-600 dark:text-primary-400">
                                        Usul dari nama bahan: {{ $hint }}
                                    </div>
                                @endif
                            </td>
                            <td class="py-2 pr-4">{{ $row['from_unit'] ?: '-' }}</td>
                            <td class="py-2 pr-4">{{ $row['to_unit'] ?: '-' }}</td>
                            <td class="py-2 pr-4 text-right">{{ number_format($row['line_count'], 0, ',', '.') }}</td>
                            <td class="py-2 pr-4 text-right">{{ number_format($row['recipe_count'], 0, ',', '.') }}</td>
                            <td class="py-2 text-right">
                                <x-filament::link :href="$this->createUrl($row)" size="sm">
                                    Buat aturan
                                </x-filament::link>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-gray-500 dark:text-gray-400">
                                Tidak ada pasangan satuan yang tertahan. Seluruh baris resep yang sudah
                                tertaut ke bahan bisa dihitung.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
