<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Harga dari PO Workflow v2 adalah harga lapak (revisi user 3 Nov 2026).
 *
 * Di v2 hanya ada satu kolom harga, dan yang diketik admin di sana ternyata
 * harga untuk lapak owner. Harga itu disalin ke Harga Lapak untuk menu asal v2
 * yang lapaknya masih kosong. Harga Jual sengaja tidak dikosongkan: harga PO
 * customer biasa diambil dari kolom itu, jadi mengosongkannya membuat PO
 * bernilai Rp 0. Admin mengganti Harga Jual sendiri untuk menu yang harga
 * jualnya berbeda.
 *
 * Tidak disentuh: menu yang dibuat di 3S ONE (origin kosong, harganya memang
 * harga jual), menu yang harga lapaknya sudah diisi, dan menu berharga 0.
 * Lewat query langsung, bukan model: harga lapak tidak dicatat di riwayat
 * harga dan tidak membunyikan lonceng.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->where('origin', 'v2')
            ->whereNull('lapak_price')
            ->where('base_price', '>', 0)
            ->update(['lapak_price' => DB::raw('base_price')]);
    }

    public function down(): void
    {
        // Pendekatan: yang lapaknya masih sama dengan harga jual dianggap hasil
        // salinan ini. Lapak yang sudah diubah sesudahnya dibiarkan.
        DB::table('products')
            ->where('origin', 'v2')
            ->whereColumn('lapak_price', 'base_price')
            ->update(['lapak_price' => null]);
    }
};
