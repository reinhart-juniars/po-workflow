{{--
  Rincian HPP di bawah Bahan Baku Terpakai: Barang Hilang, Barang Temuan, dan
  Barang Sisa Dibuang. Nilainya SUDAH termasuk di Bahan Baku Terpakai (kolom
  dalam, bukan dijumlah lagi) -- terpisah dari Kerugian Barang Rusak yang ada
  di Pengeluaran. Hanya baris yang bernilai yang tampil.

  Parameter: $profitLoss, $fmt, $labelClass, $valueClass.
--}}
@php
  $hppDetailRows = collect([
      ['label' => 'Barang Hilang', 'amount' => (float) ($profitLoss['barangHilang'] ?? 0)],
      // Temuan mengurangi HPP: ditampilkan negatif.
      ['label' => 'Barang Temuan', 'amount' => -1 * (float) ($profitLoss['barangTemuan'] ?? 0)],
      ['label' => 'Barang Sisa Dibuang', 'amount' => (float) ($profitLoss['barangSisaDibuang'] ?? 0)],
  ])->filter(fn (array $row) => abs($row['amount']) >= 0.005);
@endphp
@foreach ($hppDetailRows as $row)
  <tr class="hpp-detail-row">
    <td class="{{ $labelClass }}" style="padding-left: 2.25em; font-style: italic;">termasuk {{ $row['label'] }}</td>
    <td class="{{ $valueClass }}" style="font-style: italic;">{{ $fmt($row['amount']) }}</td>
    <td class="{{ $valueClass }}"></td>
  </tr>
@endforeach
