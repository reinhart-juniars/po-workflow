{{--
  Persediaan Barang Sisa di blok HPP Laba Rugi (setelah Sisa Stok): awal
  menambah, akhir mengurangi Bahan Baku Terpakai. Hanya tampil bila ada nilai.

  Parameter: $profitLoss, $fmt (closure format rupiah),
             $labelClass ('indent' web / 'indent-cell' ekspor), $valueClass ('value-cell' / 'num').
--}}
@if (abs((float) ($profitLoss['barangSisaAwal'] ?? 0)) >= 0.005 || abs((float) ($profitLoss['barangSisaAkhir'] ?? 0)) >= 0.005)
  <tr>
    <td class="{{ $labelClass }}">Barang Sisa Awal</td>
    <td class="{{ $valueClass }}"></td>
    <td class="{{ $valueClass }}">{{ $fmt((float) ($profitLoss['barangSisaAwal'] ?? 0)) }}</td>
  </tr>
  <tr>
    <td class="{{ $labelClass }}">Barang Sisa Akhir</td>
    <td class="{{ $valueClass }}"></td>
    <td class="{{ $valueClass }}">{{ $fmt((float) ($profitLoss['barangSisaAkhir'] ?? 0)) }}</td>
  </tr>
@endif
