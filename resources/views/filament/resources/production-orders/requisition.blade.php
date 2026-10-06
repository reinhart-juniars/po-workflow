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
            <p class="sh-kpi-label">Jejak</p>
            <div class="sh-kpi-lines">
                <div>Diajukan: {{ $requisition?->submittedBy?->name ?? '-' }} {{ $requisition?->submitted_at?->format('d/m H:i') }}</div>
                <div>Disetujui: {{ $requisition?->approvedBy?->name ?? '-' }} {{ $requisition?->approved_at?->format('d/m H:i') }}</div>
                <div>Diperiksa: {{ $requisition?->checkedBy?->name ?? '-' }} {{ $requisition?->checked_at?->format('d/m H:i') }}</div>
            </div>
        </div>

        @if ($requisition?->isChecked())
            @php
                $pembelian = $requisition->purchases()->get();
                $rusak = $pembelian->where('condition', \App\Models\InventoryPurchase::CONDITION_DAMAGED)->sum('total_value');
            @endphp
            <div class="sh-kpi-cell">
                <p class="sh-kpi-label">Pembelian Tercatat</p>
                <p class="sh-kpi-value">{{ $rupiah($pembelian->sum('total_value')) }}</p>
                <p class="sh-kpi-note">{{ $pembelian->count() }} pembelian · {{ $requisition->paymentTypeLabel() ?? '-' }}@if ($rusak > 0) · rusak {{ $rupiah($rusak) }}@endif</p>
            </div>
        @else
            <div class="sh-kpi-cell">
                <p class="sh-kpi-label">Perkiraan Biaya Bahan</p>
                <p class="sh-kpi-value">{{ $requisition ? $rupiah($requisition->lines->sum(fn ($l) => (float) $l->required_qty * (float) ($l->unit_price ?? 0))) : '-' }}</p>
                <p class="sh-kpi-note">Kebutuhan × harga master bahan</p>
            </div>
        @endif
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
        @php $notice = $this->stageNotice(); @endphp

        {{-- Penolakan supervisor tetap tampil sampai form diajukan ulang. --}}
        @if ($requisition->wasRejected())
            <x-filament::section icon="heroicon-m-hand-thumb-down" icon-color="danger"
                :heading="'Ditolak supervisor gudang' . ($requisition->rejectedBy ? ' — ' . $requisition->rejectedBy->name : '') . ' · ' . $requisition->rejected_at?->format('d/m/Y H:i')">
                <p class="text-sm text-danger-600 dark:text-danger-400">{{ $requisition->rejection_reason }}</p>
                <p class="mt-1 text-sm text-gray-500">Perbaiki isian, Simpan, lalu Ajukan lagi.</p>
            </x-filament::section>
        @endif

        <x-filament::section heading="Kebutuhan, Stok & Pembelian">
            <x-slot name="description">
                {{ $notice['text'] }}
            </x-slot>

            {{-- Header cara pembayaran (form Filament) + tabel bahan (satu baris per bahan). --}}
            {{ $this->form }}

            <div class="mt-6">
                @include('filament.resources.production-orders.partials.requisition-lines', [
                    'stage' => $this->lineStage(),
                    'lines' => $this->data['lines'] ?? [],
                    'requisition' => $requisition,
                ])
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
