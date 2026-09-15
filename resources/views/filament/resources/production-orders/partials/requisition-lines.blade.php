{{--
  Tabel bahan Form Kebutuhan: satu baris per bahan, kolom mengikuti tahap.
  Input terikat langsung ke $data['lines'][i] (wire:model, dikirim saat
  Simpan) -- bukan Repeater, supaya 30 bahan muat di satu layar tanpa
  kartu bertumpuk. Kolom tahap yang sudah lewat tampil sebagai teks.

  $stage: draft | receiving | actuals | locked
  $lines: array baris ($data['lines'])
--}}
@php
    $qty = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    $rupiah = fn ($v) => $v === null || $v === '' ? '-' : 'Rp ' . number_format((float) $v, 0, ',', '.');
    $receivingVisible = in_array($stage, ['receiving', 'actuals', 'locked'], true) && ($requisition?->isApproved() || $requisition?->isChecked());
    $actualsVisible = $requisition?->isChecked() ?? false;
    $roundUp = app(\App\Support\Settings\Settings::class)->bool('requisition.round_purchase_up') ? 'true' : 'false';
    // Meja supervisor: nilai perkiraan per bahan supaya keputusan setujui/tolak punya dasar.
    $showEstimate = $stage === 'draft' || ($requisition?->isSubmitted() ?? false);
    $defaultTreatment = app(\App\Support\Settings\Settings::class)->get('requisition.reject_default_treatment');
@endphp

<div class="sh-grid-wrap" x-data="{ roundUp: {{ $roundUp }},
    n(v) { const x = parseFloat(String(v ?? '').replace(',', '.')); return isNaN(x) ? null : x; },
    fmt(v) { return v === null ? '' : Number(v.toFixed(4)).toLocaleString('id-ID', { maximumFractionDigits: 4 }); },
    suggest(i) { const l = $wire.data.lines[i]; const o = this.n(l.opening_stock_qty) ?? 0; let s = Math.max((this.n(l.required_qty) ?? 0) - o, 0); if (this.roundUp) s = Math.ceil(s); $wire.data.lines[i].purchase_qty = Number(s.toFixed(4)); },
    rejected(i) { const l = $wire.data.lines[i]; const b = this.n(l.purchase_qty) ?? 0; const r = this.n(l.received_qty); return Math.max(b - (r === null ? b : r), 0); } }">
<table class="sh-grid">
    <colgroup>
        <col>
        <col style="width: 76px">
        @if ($stage === 'draft')
            <col style="width: 104px"><col style="width: 104px"><col style="width: 100px"><col style="width: 180px">
        @elseif ($showEstimate)
            <col style="width: 90px"><col style="width: 90px"><col style="width: 110px"><col style="width: 120px"><col style="width: 180px">
        @else
            <col style="width: 56px"><col style="width: 60px">
            @if ($receivingVisible)
                <col style="width: 84px"><col style="width: 66px">
                @if ($stage === 'receiving')
                    <col style="width: 112px"><col style="width: 126px"><col style="width: 96px">
                @else
                    <col style="width: 130px"><col style="width: 90px">
                @endif
            @endif
            @if ($actualsVisible)
                <col style="width: 92px"><col style="width: 90px">
            @endif
            <col style="width: 96px">
        @endif
    </colgroup>
    <thead>
        <tr>
            <th>Bahan</th>
            <th class="num">Kebutuhan</th>
            <th class="num">{{ $stage === 'draft' ? 'Stok Awal' : 'Stok' }}</th>
            <th class="num">Beli</th>
            @if ($stage === 'draft')
                <th class="num">Harga Master</th>
            @elseif ($showEstimate)
                <th class="num">Harga Master</th>
                <th class="num">Perkiraan</th>
            @endif
            @if ($receivingVisible)
                <th class="num">Diterima</th>
                <th class="num">Ditolak</th>
                <th>Alasan Ditolak</th>
                @if ($stage === 'receiving')
                    <th>Perlakuan</th>
                @endif
                <th class="num">Harga Beli</th>
            @endif
            @if ($actualsVisible)
                <th class="num">Pemakaian</th>
                <th class="num">Sisa Stok</th>
            @endif
            <th>Catatan</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $i => $line)
            <tr wire:key="line-{{ $line['id'] }}">
                <td>
                    <div class="sh-grid-name" title="{{ $line['name'] }}">{{ $line['name'] }}</div>
                    <div class="sh-grid-unit">{{ $line['unit'] }}</div>
                </td>
                <td class="num">{{ $qty($line['required_qty']) }}</td>

                @if ($stage === 'draft')
                    <td class="num">
                        <input type="number" step="any" min="0" class="sh-grid-input" inputmode="decimal"
                            wire:model="data.lines.{{ $i }}.opening_stock_qty" x-on:input="suggest({{ $i }})" placeholder="0">
                    </td>
                    <td class="num">
                        <input type="number" step="any" min="0" class="sh-grid-input" inputmode="decimal"
                            wire:model="data.lines.{{ $i }}.purchase_qty" title="Kebutuhan − Stok Awal; boleh dibulatkan ke kemasan">
                    </td>
                    <td class="num sh-grid-muted">{{ $rupiah($line['unit_price']) }}</td>
                @else
                    <td class="num sh-grid-muted">{{ $qty($line['opening_stock_qty']) }}</td>
                    <td class="num">{{ $qty($line['purchase_qty']) }}</td>
                    @if ($showEstimate)
                        <td class="num sh-grid-muted">{{ $rupiah($line['unit_price']) }}</td>
                        <td class="num">{{ $rupiah((float) $line['purchase_qty'] * (float) ($line['unit_price'] ?? 0)) }}</td>
                    @endif
                @endif

                @if ($receivingVisible)
                    @if ($stage === 'receiving')
                        <td class="num">
                            <input type="number" step="any" min="0" class="sh-grid-input" inputmode="decimal"
                                wire:model="data.lines.{{ $i }}.received_qty" placeholder="= beli">
                        </td>
                        <td class="num" :class="rejected({{ $i }}) > 0 ? 'sh-grid-bad' : 'sh-grid-muted'" x-text="fmt(rejected({{ $i }}))"></td>
                        <td>
                            <input type="text" maxlength="255" class="sh-grid-input" wire:model="data.lines.{{ $i }}.rejected_reason"
                                x-bind:placeholder="rejected({{ $i }}) > 0 ? 'wajib: mis. busuk' : ''" x-bind:disabled="rejected({{ $i }}) <= 0">
                        </td>
                        <td>
                            <select class="sh-grid-input" wire:model="data.lines.{{ $i }}.rejected_treatment" x-bind:disabled="rejected({{ $i }}) <= 0">
                                <option value="">bawaan: {{ $defaultTreatment === \App\Models\RequisitionLine::REJECT_PAID ? 'dibayar' : 'retur' }}</option>
                                @foreach (\App\Models\RequisitionLine::rejectTreatmentOptions() as $k => $label)
                                    <option value="{{ $k }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="num">
                            <input type="number" step="any" min="0" class="sh-grid-input" inputmode="decimal"
                                wire:model="data.lines.{{ $i }}.purchase_price"
                                placeholder="{{ $line['unit_price'] === null ? 'wajib' : number_format((float) $line['unit_price'], 0, ',', '.') }}"
                                title="Harga beli per {{ $line['unit'] }} dari nota; kosong = harga master">
                        </td>
                    @else
                        @php $ditolak = max((float) $line['purchase_qty'] - (float) ($line['received_qty'] ?? $line['purchase_qty']), 0); @endphp
                        <td class="num">{{ $qty($line['received_qty'] ?? $line['purchase_qty']) }}</td>
                        <td class="num {{ $ditolak > 0 ? 'sh-grid-bad' : 'sh-grid-muted' }}">{{ $qty($ditolak) }}</td>
                        <td class="sh-grid-muted">{{ $line['rejected_reason'] }}@if ($ditolak > 0 && $line['rejected_treatment']) · {{ \App\Models\RequisitionLine::rejectTreatmentOptions()[$line['rejected_treatment']] ?? $line['rejected_treatment'] }}@endif</td>
                        <td class="num">{{ $rupiah($line['purchase_price'] ?? $line['unit_price']) }}</td>
                    @endif
                @endif

                @if ($actualsVisible)
                    @if ($stage === 'actuals')
                        <td class="num">
                            <input type="number" step="any" min="0" class="sh-grid-input" inputmode="decimal"
                                wire:model="data.lines.{{ $i }}.actual_used_qty" placeholder="= kebutuhan">
                        </td>
                        <td class="num">
                            <input type="number" step="any" min="0" class="sh-grid-input" inputmode="decimal"
                                wire:model="data.lines.{{ $i }}.remaining_qty" placeholder="tidak dihitung">
                        </td>
                    @else
                        <td class="num">{{ $qty($line['actual_used_qty'] ?? $line['required_qty']) }}</td>
                        <td class="num sh-grid-muted">{{ $qty($line['remaining_qty']) }}</td>
                    @endif
                @endif

                <td>
                    @if ($stage !== 'locked')
                        <input type="text" maxlength="255" class="sh-grid-input" wire:model="data.lines.{{ $i }}.notes">
                    @else
                        <span class="sh-grid-muted">{{ $line['notes'] }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
</div>
