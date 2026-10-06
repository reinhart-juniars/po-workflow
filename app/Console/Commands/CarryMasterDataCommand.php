<?php

namespace App\Console\Commands;

use App\Services\MasterMenu\MasterDataCarryOver;
use App\Support\Settings\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Membawa data master dari database latihan Oktober ke database resmi November.
 *
 * Dijalankan sekali di hari pindah, setelah database resmi dibuat dari dump v2
 * akhir Oktober dan dimigrasi (docs/DEPLOY-DIGITALOCEAN.md §14). Database latihan
 * hanya dibaca. Aturan apa yang dibawa ada di MasterDataCarryOver.
 */
class CarryMasterDataCommand extends Command
{
    private const SOURCE_CONNECTION = 'carry_source';

    protected $signature = 'inventory:carry-master-data
        {--from-database= : Nama database latihan (mis. po_workflow_latihan)}
        {--database= : Koneksi tujuan (mis. mysql_staging); default koneksi aktif}
        {--dry-run : Jalankan seluruh proses lalu batalkan, untuk melihat dampaknya dulu}
        {--force : Lewati konfirmasi}';

    protected $description = 'Bawa bahan, resep, pemetaan menu, supplier, pengaturan, dan akun dari database latihan ke database resmi';

    public function handle(): int
    {
        $from = trim((string) $this->option('from-database'));

        if ($from === '') {
            $this->error('Isi --from-database dengan nama database latihan, mis. --from-database=po_workflow_latihan.');

            return self::FAILURE;
        }

        $targetName = $this->option('database') ?: config('database.default');

        if (! config()->has('database.connections.'.$targetName)) {
            $this->error("Koneksi '{$targetName}' tidak terdaftar di config/database.php.");

            return self::FAILURE;
        }

        $target = DB::connection($targetName);

        // Pagar utama: sumber dan tujuan yang sama berarti membaca dan menulis
        // database yang sama, dan pemeriksaan "tujuan masih kosong" tidak berarti.
        if (self::sameDatabase($from, $target->getDatabaseName())) {
            $this->error("Database latihan sama dengan tujuan ({$from}). Dibatalkan.");

            return self::FAILURE;
        }

        // Sumber = koneksi tujuan dengan nama database lain (server dan akun sama).
        Config::set('database.connections.'.self::SOURCE_CONNECTION, array_merge(
            Config::get('database.connections.'.$targetName),
            ['database' => $from],
        ));
        DB::purge(self::SOURCE_CONNECTION);

        $this->line("Latihan : {$from}");
        $this->line('Tujuan  : '.$target->getDatabaseName());

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Mode uji-jalan: seluruh perubahan dibatalkan di akhir.');
        } elseif (! $this->option('force') && ! $this->confirm('Bawa data master ke database tujuan di atas?', false)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        try {
            $result = (new MasterDataCarryOver(DB::connection(self::SOURCE_CONNECTION), $target))->run($dryRun);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Gagal, tidak ada yang tersimpan: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            DB::purge(self::SOURCE_CONNECTION);
        }

        $this->newLine();
        $this->table(
            ['Kelompok', 'Di latihan', 'Dibawa', 'Keterangan'],
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

        $this->info('Data master terbawa. Lanjutkan: php artisan inventory:validate-migration');

        return self::SUCCESS;
    }

    private static function sameDatabase(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // SQLite: nama database adalah path berkas, yang bisa ditulis berbeda.
        $realA = is_file($a) ? realpath($a) : false;
        $realB = is_file($b) ? realpath($b) : false;

        return $realA !== false && $realA === $realB;
    }
}
