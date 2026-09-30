<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Rincian HPP di bawah Bahan Baku Terpakai (Barang Hilang, Barang Temuan,
 * Barang Sisa Dibuang). Nilainya SUDAH termasuk di Bahan Baku Terpakai --
 * dipakai bersama oleh tampilan web (bisa dibuka-tutup) dan ekspor (selalu
 * tampil), jadi daftarnya dihitung di satu tempat.
 */
final class HppDetailRows
{
    /**
     * Hanya baris yang bernilai yang dikembalikan.
     *
     * @param  array<string, mixed>  $profitLoss
     * @return Collection<int, array{label: string, amount: float}>
     */
    public static function from(array $profitLoss): Collection
    {
        return collect([
            ['label' => 'Barang Hilang', 'amount' => (float) ($profitLoss['barangHilang'] ?? 0)],
            // Temuan mengurangi HPP: ditampilkan negatif.
            ['label' => 'Barang Temuan', 'amount' => -1 * (float) ($profitLoss['barangTemuan'] ?? 0)],
            ['label' => 'Barang Sisa Dibuang', 'amount' => (float) ($profitLoss['barangSisaDibuang'] ?? 0)],
        ])->filter(fn (array $row) => abs($row['amount']) >= 0.005)->values();
    }
}
