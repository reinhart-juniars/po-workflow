{{--
  Detail Tagihan Pembelian (Inventory). Hanya kelas shell.css / komponen
  Filament: CSS panel tidak memuat utilitas responsif Tailwind aplikasi.
--}}
@php
    $bill = $this->getBill()->loadMissing(['purchases.item', 'requisition.productionOrder', 'submitter', 'decider', 'cashAccount']);
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0';
    $tanggal = fn ($d) => $d?->translatedFormat('d M Y');
@endphp

<x-filament-panels::page>
    <div class="sh-kpi" style="--cols: 4">
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Status</p>
            <p class="sh-kpi-value">{{ $bill->statusLabel() }}</p>
            <p class="sh-kpi-note">
                @if ($bill->status === \App\Models\PurchaseBill::STATUS_PAID)
                    Dibayar {{ $tanggal($bill->paid_on) }} dari {{ $bill->cashAccount?->name }}
                @elseif ($bill->status === \App\Models\PurchaseBill::STATUS_CREDIT)
                    Jatuh tempo {{ $tanggal($bill->due_date) }}
                @elseif ($bill->submitted_at)
                    Diajukan {{ $bill->submitted_at->translatedFormat('d M Y H:i') }}
                @else
                    Belum diajukan
                @endif
            </p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Total tagihan</p>
            <p class="sh-kpi-value">{{ $rp($bill->total) }}</p>
            <p class="sh-kpi-note">{{ $bill->purchases->count() }} baris pembelian</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Sumber</p>
            <p class="sh-kpi-value">{{ $bill->requisition?->number ?? 'Belanja lepas' }}</p>
            <p class="sh-kpi-note">{{ $bill->requisition?->productionOrder?->number ?? $tanggal($bill->bill_date) }}</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Nota</p>
            <p class="sh-kpi-value {{ $bill->receipt_path ? 'is-ok' : 'is-bad' }}">{{ $bill->receipt_path ? 'Terlampir' : 'Belum ada' }}</p>
            <p class="sh-kpi-note">{{ $bill->displaySupplier() }}</p>
        </div>
    </div>

    @if ($bill->status === \App\Models\PurchaseBill::STATUS_RETURNED && $bill->return_reason)
        <div class="sh-bd-alert" role="alert">
            <p><b>Dikembalikan accounting</b>{{ $bill->decider ? ' ('.$bill->decider->name.')' : '' }}: {{ $bill->return_reason }}</p>
            <p>Lengkapi lalu ajukan lagi.</p>
        </div>
    @endif

    <x-filament-panels::form id="form" :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()" wire:submit="save">
        {{ $this->form }}
        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
    </x-filament-panels::form>

    <x-filament::section>
        <x-slot name="heading">Bahan yang ditagihkan</x-slot>
        <x-slot name="description">Pembelian ini sudah masuk stok. Hutangnya tercatat atas nama tagihan sampai accounting membayar.</x-slot>
        <div class="sh-grid-wrap">
            <table class="sh-table">
                <thead>
                    <tr><th>Bahan</th><th>Kondisi</th><th class="text-right">Jumlah</th><th class="text-right">Harga</th><th class="text-right">Nilai</th></tr>
                </thead>
                <tbody>
                    @forelse ($bill->purchases as $purchase)
                        <tr>
                            <td>{{ $purchase->item?->name ?? '-' }}</td>
                            <td>{{ $purchase->conditionLabel() }}@if ($purchase->condition_notes) <span class="sh-grid-muted">· {{ $purchase->condition_notes }}</span>@endif</td>
                            <td class="text-right">{{ $qty($purchase->qty) }} {{ $purchase->item?->unit }}</td>
                            <td class="text-right">{{ $rp($purchase->unit_cost) }}</td>
                            <td class="text-right">{{ $rp($purchase->total_value) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="sh-grid-muted">Belum ada pembelian.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr><th colspan="4" class="text-right">Total</th><th class="text-right">{{ $rp($bill->total) }}</th></tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
