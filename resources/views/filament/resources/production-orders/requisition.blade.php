@php
    $order = $this->getOrder();
    $requisition = $this->getRequisition();
    $rupiah = fn ($value) => 'Rp ' . number_format((float) $value, 2, ',', '.');
@endphp

<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">SPK Produksi</div>
            <div class="mt-1 text-lg font-semibold">{{ $order->number }}</div>
            <div class="mt-1 text-xs text-gray-400">
                {{ $order->production_date->format('d/m/Y') }} {{ $order->production_time }} · {{ $order->statusLabel() }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Status Form</div>
            <div class="mt-1 text-lg font-semibold">{{ $requisition?->statusLabel() ?? 'Belum disusun' }}</div>
            @if ($requisition)
                <div class="mt-1 text-xs text-gray-400">{{ $requisition->number }}</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Jejak Persetujuan</div>
            <div class="mt-1 space-y-0.5 text-xs">
                <div>Dibuat: {{ $requisition?->preparedBy?->name ?? '-' }} {{ $requisition?->prepared_at?->format('d/m H:i') }}</div>
                <div>Disetujui: {{ $requisition?->approvedBy?->name ?? '-' }} {{ $requisition?->approved_at?->format('d/m H:i') }}</div>
                <div>Diperiksa: {{ $requisition?->checkedBy?->name ?? '-' }} {{ $requisition?->checked_at?->format('d/m H:i') }}</div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Perkiraan Biaya Bahan</div>
            <div class="mt-1 text-lg font-semibold">
                {{ $requisition ? $rupiah($requisition->lines->sum(fn ($l) => (float) $l->required_qty * (float) ($l->unit_price ?? 0))) : '-' }}
            </div>
            <div class="mt-1 text-xs text-gray-400">Kebutuhan × harga satuan bahan.</div>
        </x-filament::section>
    </div>

    @if (! $requisition)
        @php $preview = $this->getRequirementsPreview(); @endphp
        <x-filament::section heading="Pratinjau Kebutuhan">
            <x-slot name="description">
                Form belum disusun. Tekan "Susun Form" untuk membuat baris kebutuhan dari resep × jumlah menu.
            </x-slot>

            @if ($preview['issues'])
                <ul class="mb-3 list-disc space-y-1 pl-5 text-sm text-danger-600 dark:text-danger-400">
                    @foreach ($preview['issues'] as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                            <th class="py-2 pr-4 font-medium">Bahan</th>
                            <th class="py-2 pr-4 text-right font-medium">Kebutuhan</th>
                            <th class="py-2 pr-4 font-medium">Dipakai oleh</th>
                            <th class="py-2 text-right font-medium">Biaya</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($preview['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-4">{{ $row['name'] }}</td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap">{{ static::qty($row['qty']) }} {{ $row['unit'] }}</td>
                                <td class="py-2 pr-4 text-gray-500">{{ implode(', ', $row['menus']) }}</td>
                                <td class="py-2 text-right">{{ $rupiah($row['total_cost']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-500">Belum ada baris menu yang bisa dihitung.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @else
        <x-filament::section heading="Kebutuhan, Stok & Pembelian">
            <x-slot name="description">
                @if ($requisition->isDraft())
                    Isi Stok Awal hasil hitungan fisik; Beli = Kebutuhan − Stok Awal dan boleh dibulatkan ke kemasan. Simpan lalu Setujui.
                @elseif ($requisition->isApproved())
                    Isian dikunci. Tekan "Periksa" setelah barang dibeli dan diperiksa.
                @elseif (! $order->isCompleted())
                    Barang sudah tercatat masuk. Isi Pemakaian Aktual dan Sisa Stok bila dihitung, lalu Tutup SPK.
                @else
                    SPK sudah ditutup; pemakaian sudah dicatat ke kartu stok.
                @endif
            </x-slot>

            {{ $this->form }}
        </x-filament::section>
    @endif
</x-filament-panels::page>
