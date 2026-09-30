{{--
  Rincian HPP di bawah Bahan Baku Terpakai: Barang Hilang, Barang Temuan, dan
  Barang Sisa Dibuang. Nilainya SUDAH termasuk di Bahan Baku Terpakai (kolom
  dalam, bukan dijumlah lagi) -- terpisah dari Kerugian Barang Rusak yang ada
  di Pengeluaran. Hanya baris yang bernilai yang tampil.

  Parameter: $profitLoss, $fmt, $labelClass, $valueClass,
             $collapsible (opsional, web saja): tersembunyi sampai baris
             Bahan Baku Terpakai diklik (lihat pl-bahan-baku-terpakai).
--}}
@php
  $hppDetailRows = \App\Support\HppDetailRows::from($profitLoss);
  $collapsible = $collapsible ?? false;
@endphp
@foreach ($hppDetailRows as $row)
  <tr class="hpp-detail-row" @if ($collapsible) data-hpp-detail hidden @endif>
    <td class="{{ $labelClass }}" style="padding-left: 2.25em; font-style: italic;">termasuk {{ $row['label'] }}</td>
    <td class="{{ $valueClass }}" style="font-style: italic;">{{ $fmt($row['amount']) }}</td>
    <td class="{{ $valueClass }}"></td>
  </tr>
@endforeach
