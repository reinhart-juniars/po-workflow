<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class AutoNumberHelper
{
    public static function generate(string $table, string $field, string $prefix): string
    {
        $datePart = now()->format('Ymd');
        // Ambil nomor terakhir berdasarkan prefix + tanggal
        $latest = DB::table($table)
            ->where($field, 'like', "{$prefix}-{$datePart}-%")
            ->orderBy($field, 'desc')
            ->value($field);

        // Ambil urutan terakhir
        $lastNumber = 0;
        if ($latest) {
            $parts = explode('-', $latest);
            $last = end($parts);
            $lastNumber = intval($last);
        }

        // Tambah 1 nomor berikutnya
        $newNumber = str_pad((string)($lastNumber + 1), 4, '0', STR_PAD_LEFT);
        return "{$prefix}-{$datePart}-{$newNumber}";
    }
}