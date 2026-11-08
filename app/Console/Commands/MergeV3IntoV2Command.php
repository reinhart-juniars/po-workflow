<?php

namespace App\Console\Commands;

use App\Services\Cutover\MergeV3IntoV2;
use App\Support\Settings\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Cutover ulang 9 Nov 2026: tempelkan pekerjaan V3 (menu, resep, inventory,
 * SPK Produksi, PO draft) ke database baru yang dibuat dari dump V2 final.
 *
 * Urutan di server: dump V2 final → database baru → php artisan migrate →
 * perintah ini (uji-jalan dulu) → arahkan DB_DATABASE ke database baru.
 * Database V3 lama hanya dibaca. Aturannya ada di MergeV3IntoV2.
 */
class MergeV3IntoV2Command extends Command
{
    private const SOURCE_CONNECTION = 'merge_v3_source';

    private const BASE_CONNECTION = 'merge_v3_base';

    protected $signature = 'cutover:merge-v3
        {--from-database= : Nama database V3 yang sedang dipakai (mis. po_workflow)}
        {--base-database= : Database berisi dump V2 saat V3 dibangun (titik pisah), untuk menggabung menu per kolom}
        {--database= : Koneksi tujuan; default koneksi aktif}
        {--since='.MergeV3IntoV2::DEFAULT_SINCE.' : Tanggal V2 dan V3 mulai berjalan terpisah}
        {--dry-run : Jalankan seluruh proses lalu batalkan, untuk melihat dampaknya dulu}
        {--force : Lewati konfirmasi}';

    protected $description = 'Gabungkan menu, resep, inventory, SPK Produksi, dan PO draft dari database V3 ke database baru hasil dump V2 final';

    public function handle(): int
    {
        $from = trim((string) $this->option('from-database'));

        if ($from === '') {
            $this->error('Isi --from-database dengan nama database V3, mis. --from-database=po_workflow.');

            return self::FAILURE;
        }

        $targetName = $this->option('database') ?: config('database.default');

        if (! config()->has('database.connections.'.$targetName)) {
            $this->error("Koneksi '{$targetName}' tidak terdaftar di config/database.php.");

            return self::FAILURE;
        }

        $target = DB::connection($targetName);

        if ($from === $target->getDatabaseName() || (is_file($from) && realpath($from) === realpath($target->getDatabaseName()))) {
            $this->error("Database V3 sama dengan tujuan ({$from}). Dibatalkan.");

            return self::FAILURE;
        }

        // Sumber = koneksi tujuan dengan nama database lain (server dan akun sama).
        Config::set('database.connections.'.self::SOURCE_CONNECTION, array_merge(
            Config::get('database.connections.'.$targetName),
            ['database' => $from],
        ));
        DB::purge(self::SOURCE_CONNECTION);

        $baseName = trim((string) $this->option('base-database'));

        if ($baseName !== '') {
            Config::set('database.connections.'.self::BASE_CONNECTION, array_merge(
                Config::get('database.connections.'.$targetName),
                ['database' => $baseName],
            ));
            DB::purge(self::BASE_CONNECTION);
        } else {
            $this->warn('Tanpa --base-database: menu yang disentuh V3 diambil utuh dari V3 (perubahan V2 di menu yang sama tertimpa).');
        }

        $this->line("V3 (dibaca) : {$from}");
        $this->line('Titik pisah : '.($baseName ?: '-'));
        $this->line('Tujuan      : '.$target->getDatabaseName());

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Mode uji-jalan: seluruh perubahan dibatalkan di akhir.');
        } elseif (! $this->option('force') && ! $this->confirm('Gabungkan data V3 ke database tujuan di atas?', false)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        try {
            $result = (new MergeV3IntoV2(
                DB::connection(self::SOURCE_CONNECTION),
                $target,
                (string) $this->option('since'),
                $baseName !== '' ? DB::connection(self::BASE_CONNECTION) : null,
            ))->run($dryRun);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Gagal, tidak ada yang tersimpan: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            DB::purge(self::SOURCE_CONNECTION);
            DB::purge(self::BASE_CONNECTION);
        }

        $this->newLine();
        $this->table(
            ['Kelompok', 'Di V3', 'Dibawa', 'Keterangan'],
            array_map(fn ($row) => [$row[0], number_format($row[1], 0, ',', '.'), number_format($row[2], 0, ',', '.'), $row[3]], $result['summary']),
        );

        foreach ($result['warnings'] as $warning) {
            $this->warn('• '.$warning);
        }

        if ($dryRun) {
            $this->warn('Uji-jalan selesai. Tidak ada data yang tersimpan.');

            return self::SUCCESS;
        }

        // Pengaturan dan izin di-cache; tanpa ini aplikasi masih membaca nilai lama.
        app(Settings::class)->flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Data V3 tergabung. Lanjutkan: php artisan inventory:validate-migration, lalu arahkan DB_DATABASE ke database ini.');

        return self::SUCCESS;
    }
}
