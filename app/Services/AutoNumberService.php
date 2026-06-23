<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutoNumberService
{
    /**
     * Tampilkan calon nomor tanpa mengunci counter (buat preview di form).
     */
    public function peek(string $prefix): string
    {
        [$today, $seq] = $this->currentSeq($prefix);
        return $this->format($prefix, $today, $seq + 1);
    }

    /**
     * Ambil nomor final (dipakai saat simpan).
     * Catatan: ini “optimistic”. Pastikan kolom po_number UNIQUE, kalau bentrok — retry.
     */
    public function next(string $prefix): string
    {
        [$today, $seq] = $this->currentSeq($prefix);
        return $this->format($prefix, $today, $seq + 1);
    }

    /**
     * Ambil sequence tertinggi untuk hari ini berdasarkan isi tabel purchase_orders.
     */
    protected function currentSeq(string $prefix): array
    {
        $today = now()->format('Ymd');

        // Cari po_number paling besar untuk prefix-hari ini, contoh: PO-20251103-0007
        $max = DB::table('purchase_orders')
            ->where('po_number', 'like', "{$prefix}-{$today}-%")
            ->max('po_number');

        $seq = 0;
        if ($max) {
            // Ambil 4 digit terakhir sbg sequence
            if (preg_match('/(\d{4})$/', $max, $m)) {
                $seq = (int) $m[1];
            }
        }
        return [$today, $seq];
    }

    protected function format(string $prefix, string $dateYmd, int $seq): string
    {
        return sprintf('%s-%s-%04d', $prefix, $dateYmd, $seq);
    }
}
