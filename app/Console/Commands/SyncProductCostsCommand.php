<?php

namespace App\Console\Commands;

use App\Services\ProductRecipeCostSync;
use Illuminate\Console\Command;

/**
 * Hitung ulang HPP & OHC semua produk bertaut resep. Hook model sudah
 * menyinkronkan setiap perubahan; perintah ini untuk deploy pertama dan
 * jadwal harian sebagai jaring pengaman (mis. harga bahan diubah lewat SQL).
 */
class SyncProductCostsCommand extends Command
{
    protected $signature = 'menu:sync-product-costs';

    protected $description = 'Sinkronkan HPP & OHC produk dari resep di aplikasi Menu';

    public function handle(ProductRecipeCostSync $sync): int
    {
        $tally = $sync->syncAll();

        $this->info("Mengikuti resep: {$tally['resep']} · tertahan (resep belum lengkap): {$tally['tertahan']} · manual: {$tally['manual']}");

        return self::SUCCESS;
    }
}
