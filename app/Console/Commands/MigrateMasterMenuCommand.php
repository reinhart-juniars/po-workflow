<?php

namespace App\Console\Commands;

use App\Services\MasterMenu\MasterMenuMigrationService;
use App\Services\MasterMenu\MasterMenuSource;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Memindahkan master data Master Menu Revamp ke Modul Inventory Terpadu.
 *
 * Dibuat untuk dijalankan berkali-kali: data di sisi klien terus diisi sampai
 * hari cutover, jadi perpindahannya harus bisa diulang tanpa menggandakan apa
 * pun. Sumber (app.db) hanya dibaca.
 */
class MigrateMasterMenuCommand extends Command
{
    protected $signature = 'inventory:migrate-master-menu
        {--db= : Path file app.db Master Menu (default: config master_menu.database)}
        {--database= : Koneksi tujuan (mis. mysql_staging); default koneksi aktif}
        {--dry-run : Jalankan seluruh proses lalu batalkan, untuk melihat dampaknya dulu}
        {--force : Lewati konfirmasi}';

    protected $description = 'Pindahkan bahan, histori harga, resep, dan daftar bahan belum cocok dari Master Menu Revamp';

    public function handle(): int
    {
        try {
            $source = new MasterMenuSource($this->option('db'));
            $this->line('Sumber : '.$source->path());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Perpindahan diuji di staging lebih dulu, jadi tujuannya harus bisa
        // diarahkan tanpa menyunting .env.
        if ($connection = $this->option('database')) {
            if (! config()->has('database.connections.'.$connection)) {
                $this->error("Koneksi '{$connection}' tidak terdaftar di config/database.php.");

                return self::FAILURE;
            }

            config(['database.default' => $connection]);
            \Illuminate\Support\Facades\DB::purge($connection);
        }

        $this->line('Tujuan : '.config('database.connections.'.config('database.default').'.database'));

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Mode uji-jalan: seluruh perubahan dibatalkan di akhir.');
        } elseif (! $this->option('force') && ! $this->confirm('Jalankan perpindahan data ke database di atas?', false)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        try {
            $summary = app(MasterMenuMigrationService::class, ['source' => $source])->run($dryRun);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $rows = [];

        foreach ($summary as $stage => $counts) {
            foreach ($counts as $label => $value) {
                $rows[] = [str_replace('_', ' ', $stage), str_replace('_', ' ', $label), number_format($value, 0, ',', '.')];
            }
        }

        $this->table(['Tahap', 'Keterangan', 'Jumlah'], $rows);

        if ($dryRun) {
            $this->warn('Uji-jalan selesai. Tidak ada data yang tersimpan.');
        } else {
            $this->info('Perpindahan selesai.');
        }

        return self::SUCCESS;
    }
}
