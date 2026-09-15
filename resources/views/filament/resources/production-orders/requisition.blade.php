@php
    $order = $this->getOrder();
    $requisition = $this->getRequisition();
    $rupiah = fn ($value) => 'Rp ' . number_format((float) $value, 2, ',', '.');
@endphp

<x-filament-panels::page>
    <div class="sh-kpi" style="--cols: 4">
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">SPK Produksi</p>
            <p class="sh-kpi-value">{{ $order->number }}</p>
            <p class="sh-kpi-note">{{ $order->production_date->format('d/m/Y') }} {{ $order->production_time }} · {{ $order->statusLabel() }}</p>
        </div>

        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Status Form</p>
            <p class="sh-kpi-value">{{ $requisition?->statusLabel() ?? 'Belum disusun' }}</p>
            @if ($requisition)
                <p class="sh-kpi-note">{{ $requisition->number }}</p>
            @endif
        </div>

        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Jejak Persetujuan</p>
            <div class="sh-kpi-lines">
                <div>Dibuat: {{ $requisition?->preparedBy?->name ?? '-' }} {{ $requisition?->prepared_at?->format('d/m H:i') }}</div>
                <div>Disetujui: {{ $requisition?->approvedBy?->name ?? '-' }} {{ $requisition?->approved_at?->format('d/m H:i') }}</div>
                <div>Diperiksa: {{ $requisition?->checkedBy?->name ?? '-' }} {{ $requisition?->checked_at?->format('d/m H:i') }}</div>
            </div>
        </div>

        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Perkiraan Biaya Bahan</p>
            <p class="sh-kpi-value">{{ $requisition ? $rupiah($requisition->lines->sum(fn ($l) => (float) $l->required_qty * (float) ($l->unit_price ?? 0))) : '-' }}</p>
            <p class="sh-kpi-note">Kebutuhan × harga satuan bahan</p>
        </div>
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
                <table class="sh-table">
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
