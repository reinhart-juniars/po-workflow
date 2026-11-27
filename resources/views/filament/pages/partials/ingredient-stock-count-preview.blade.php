{{--
  Pratinjau Import Opname Bahan: isi berkas sebelum dicatat ke kartu stok.
  Variabel dari halaman induk: $preview, $qty, $rupiah, dan properti import*.
--}}
@php
    $summary = $preview['summary'];
    $labels = [
        'hilang' => ['Barang Hilang', 'danger'],
        'temuan' => ['Barang Temuan', 'success'],
        'saldo_awal' => ['Saldo Awal', 'info'],
        'sesuai' => ['Sesuai', 'gray'],
        'kosong' => ['Tidak dicatat (0, belum tercatat)', 'gray'],
    ];
@endphp

<x-filament::section>
    <x-slot name="heading">Pratinjau import: {{ $importFile }}</x-slot>
    <x-slot name="description">
        Belum ada yang tersimpan. Periksa selisihnya; kalau ada angka yang salah, perbaiki di Excel lalu import ulang.
    </x-slot>

    <dl class="sh-opname-summary">
        <div>
            <dt>Tanggal opname</dt>
            <dd>{{ \Illuminate\Support\Carbon::parse($importDate)->translatedFormat('d M Y') }}</dd>
            <dd class="is-note">{{ $importDateNote }}</dd>
        </div>
        <div>
            <dt>Barang Hilang</dt>
            <dd class="is-loss">{{ $summary['hilang'] }} bahan &middot; {{ $rupiah($summary['hilang_value']) }}</dd>
        </div>
        <div>
            <dt>Barang Temuan</dt>
            <dd class="is-gain">{{ $summary['temuan'] }} bahan &middot; {{ $rupiah($summary['temuan_value']) }}</dd>
        </div>
        <div>
            <dt>Saldo awal baru</dt>
            <dd>{{ $summary['saldo_awal'] }} bahan</dd>
        </div>
        <div>
            <dt>Sesuai kartu stok</dt>
            <dd>{{ $summary['sesuai'] }} bahan</dd>
            <dd class="is-note">{{ $importBlank }} baris dikosongkan, tidak diubah</dd>
        </div>
    </dl>

    @if ($summary['tanpa_harga'] > 0)
        <p class="sh-opname-warn">{{ $summary['tanpa_harga'] }} bahan berselisih belum punya harga: jumlahnya tetap dicatat, nilai rupiahnya tidak terhitung.</p>
    @endif
</x-filament::section>

<div class="sh-grid-wrap">
    <table class="sh-grid">
        <colgroup>
            <col>
            <col style="width: 130px">
            <col style="width: 120px">
            <col style="width: 120px">
            <col style="width: 130px">
            <col style="width: 200px">
        </colgroup>
        <thead>
            <tr>
                <th>Bahan</th>
                <th class="num">Saldo Kartu Stok</th>
                <th class="num">Hitung Fisik</th>
                <th class="num">Selisih</th>
                <th class="num">Nilai</th>
                <th>Dicatat sebagai</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($preview['rows'] as $row)
                <tr wire:key="pratinjau-{{ $row['id'] }}">
                    <td>
                        <div class="sh-grid-name" title="{{ $row['name'] }}">{{ $row['name'] }}</div>
                        <div class="sh-grid-unit">{{ $row['category'] }} &middot; {{ $row['unit'] }}</div>
                    </td>
                    <td class="num {{ $row['tracked'] ? '' : 'sh-grid-muted' }}">{{ $row['tracked'] ? $qty($row['balance']) : 'belum tercatat' }}</td>
                    <td class="num">{{ $qty($row['counted']) }}</td>
                    <td class="num {{ $row['status'] === 'hilang' ? 'sh-grid-bad' : '' }}">
                        {{ $row['diff'] === null ? '–' : ($row['diff'] > 0 ? '+' : '').$qty($row['diff']) }}
                    </td>
                    <td class="num {{ $row['value'] === null && $row['status'] !== 'sesuai' ? 'sh-grid-muted' : '' }}">
                        @if (in_array($row['status'], ['sesuai', 'kosong'], true))
                            –
                        @else
                            {{ $row['value'] === null ? 'harga kosong' : $rupiah(abs($row['value'])) }}
                        @endif
                    </td>
                    <td><x-filament::badge :color="$labels[$row['status']][1]">{{ $labels[$row['status']][0] }}</x-filament::badge></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="sh-opname-actions">
    <label>
        <span>Catatan</span>
        <input type="text" wire:model="importNotes" maxlength="150" placeholder="mis. opname akhir bulan"
            class="sh-grid-input">
    </label>
    <x-filament::button color="gray" wire:click="cancelImport">Batal</x-filament::button>
    <x-filament::button wire:click="saveImport"
        wire:confirm="Simpan {{ count($importCounts) }} bahan dari berkas ke kartu stok? Selisih dicatat sebagai Barang Hilang / Barang Temuan.">
        Simpan Opname ({{ count($importCounts) }} bahan)
    </x-filament::button>
</div>
@error('importNotes')
    <p class="sh-opname-error">{{ $message }}</p>
@enderror
