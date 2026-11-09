<?php

namespace App\Console\Commands;

use App\Services\MasterMenuSync;
use App\Services\ProductRecipeCostSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Samakan Master Menu dengan Menu Utama tim menu, sekali jalan setelah
 * deploy (revisi 9 Nov 2026). Tiap Menu Utama mendapat tepat satu menu jual
 * bernama resepnya; aturannya di MasterMenuSync. Sesudahnya Harga Menu dan
 * pilihan menu di PO hanya berisi Menu Utama.
 */
class SyncMenusFromRecipesCommand extends Command
{
    protected $signature = 'menu:sync-from-recipes {--dry-run : Hitung dampaknya lalu batalkan}';

    protected $description = 'Jadikan tiap Menu Utama tepat satu menu jual bernama resepnya (Harga Menu = Menu Utama)';

    public function handle(MasterMenuSync $sync, ProductRecipeCostSync $costs): int
    {
        DB::beginTransaction();

        try {
            $tally = $sync->syncAll();
            // HPP, OHC, dan harga lapak ikut resep; dihitung di dalam transaksi
            // supaya uji-jalan juga ikut dibatalkan.
            $costs->flush();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Gagal, tidak ada yang tersimpan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Aksi', 'Jumlah'], [
            ['Menu V2 tertaut diganti nama jadi nama resep', $tally['diganti_nama']],
            ['Menu V2 tanpa resep ditautkan karena namanya sama', $tally['ditautkan']],
            ['Menu baru dibuat (Harga Non Lapak belum diisi)', $tally['dibuat']],
            ['Varian harga / menu lain dinonaktifkan', $tally['varian_nonaktif']],
        ]);

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->warn('Uji-jalan: semua perubahan dibatalkan.');

            return self::SUCCESS;
        }

        DB::commit();
        $this->info('Master Menu sudah sama dengan Menu Utama tim menu.');

        return self::SUCCESS;
    }
}
