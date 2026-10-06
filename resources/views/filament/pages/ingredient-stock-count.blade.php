@php
    $rows = $this->getRows();
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">
            Isi hasil hitung fisik bahan yang diopname; bahan yang dikosongkan tidak diubah. Selisih terhadap saldo
            kartu stok pada tanggal opname dicatat sebagai <strong>Barang Hilang</strong> (hitung kurang) atau
            <strong>Barang Temuan</strong> (hitung lebih), lalu tampil di Susut Bahan dan rincian HPP Laba Rugi.
            Bahan yang belum pernah tercatat di kartu stok: hitungan pertamanya menjadi Saldo Awal.
            Stock Opname (nilai rupiah per kategori) untuk Neraca tetap diisi seperti biasa.
        </x-slot>

        {{ $this->form }}
    </x-filament::section>

    <form wire:submit="save">
        <div class="sh-grid-wrap">
            <table class="sh-grid">
                <colgroup>
                    <col>
                    <col style="width: 150px">
                    <col style="width: 160px">
                    <col style="width: 170px">
                </colgroup>
                <thead>
                    <tr>
                        <th>Bahan</th>
                        <th class="num">Saldo Kartu Stok</th>
                        <th class="num">Hitung Fisik</th>
                        <th class="num">Selisih</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        {{-- Selisih dihitung di browser (Alpine) supaya mengetik tidak memicu request per tombol. --}}
                        {{-- Kunci ikut saldo: setelah disimpan saldonya berubah dan baris dirender ulang. --}}
                        <tr wire:key="opname-{{ $row['id'] }}-{{ $row['balance'] }}-{{ $row['tracked'] ? 1 : 0 }}"
                            x-data="{ fisik: @js($counts[$row['id']] ?? ''), saldo: {{ $row['balance'] }}, tracked: @js($row['tracked']) }"
                            x-on:opname-saved.window="fisik = ''">
                            <td>
                                <div class="sh-grid-name" title="{{ $row['name'] }}">{{ $row['name'] }}</div>
                                <div class="sh-grid-unit">
                                    {{ $row['unit'] }}
                                    @if ($row['unit_price'] === null)
                                        &middot; <span class="sh-grid-bad">harga kosong, nilai selisih tidak terhitung</span>
                                    @endif
                                </div>
                            </td>
                            <td class="num {{ $row['tracked'] ? '' : 'sh-grid-muted' }}">
                                {{ $row['tracked'] ? $qty($row['balance']) : 'belum tercatat' }}
                            </td>
                            <td class="num">
                                <input type="number" step="any" min="0" class="sh-grid-input"
                                    wire:model="counts.{{ $row['id'] }}" x-on:input="fisik = $event.target.value"
                                    aria-label="Hitung fisik {{ $row['name'] }}">
                            </td>
                            <td class="num">
                                <span x-show="fisik !== ''"
                                    x-bind:class="tracked && (Number(fisik) - saldo) < -0.00005 ? 'sh-grid-bad' : ''"
                                    x-text="! tracked
                                        ? 'Saldo awal'
                                        : ((Number(fisik) - saldo) < -0.00005 ? 'Hilang ' : ((Number(fisik) - saldo) > 0.00005 ? 'Temuan ' : 'Sesuai '))
                                            + (Math.round((Number(fisik) - saldo) * 10000) / 10000).toLocaleString('id-ID')"></span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="sh-grid-muted">Tidak ada bahan yang cocok dengan filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex justify-end">
            <x-filament::button type="submit" wire:confirm="Simpan hasil opname? Selisih akan dicatat ke kartu stok sebagai Barang Hilang / Barang Temuan.">
                Simpan Opname
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
