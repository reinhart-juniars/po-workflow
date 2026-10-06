<?php

namespace App\Console\Commands;

use App\Services\MasterMenu\MasterMenuSource;
use App\Services\MasterMenu\MigrationValidationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Laporan validasi pasca migrasi (dan pembersihan aman dengan --fix).
 *
 * Dipakai tiga kali: setelah migrasi ke staging, sebelum cutover (harus
 * bebas error), dan setelah cutover di produksi. Kode keluar 1 bila ada
 * temuan setingkat --fail-on (bawaan: error) supaya bisa dipasang di
 * runbook deploy.
 */
class ValidateMigrationCommand extends Command
{
    protected $signature = 'inventory:validate-migration
        {--db= : Path app.db Master Menu untuk pembanding jumlah (default: config master_menu.database; lewati bila kosong)}
        {--database= : Koneksi tujuan (mis. mysql_staging)}
        {--fix : Terapkan pembersihan aman (spasi nama, alias satuan, baris resep kosong) sebelum validasi}
        {--fail-on=error : Tingkat temuan yang membuat perintah gagal: error|warn|none}';

    protected $description = 'Validasi & pembersihan data pasca migrasi Master Menu';

    public function handle(MigrationValidationService $validator): int
    {
        if ($connection = $this->option('database')) {
            if (! config()->has('database.connections.'.$connection)) {
                $this->error("Koneksi '{$connection}' tidak terdaftar.");

                return self::FAILURE;
            }

            config(['database.default' => $connection]);
            DB::purge($connection);
        }

        $source = null;

        try {
            $source = new MasterMenuSource($this->option('db'));
            $source->path(); // validasi sekarang, bukan saat menghitung
        } catch (RuntimeException $e) {
            $source = null;
            $this->warn('Pembanding sumber dilewati: '.$e->getMessage());
        }

        if ($this->option('fix')) {
            $fixed = $validator->fix();
            $this->info('Pembersihan: '.collect($fixed)->map(fn ($n, $k) => "{$k}={$n}")->implode(', '));
            $this->newLine();
        }

        $findings = $validator->run($source);

        $this->table(
            ['Tingkat', 'Pemeriksaan', 'Jumlah', 'Keterangan'],
            $findings->map(fn (array $f) => [strtoupper($f['level']), $f['check'], $f['count'], $f['detail']])->all(),
        );

        $errors = $findings->where('level', MigrationValidationService::ERROR)->count();
        $warns = $findings->where('level', MigrationValidationService::WARN)->count();

        $this->newLine();
        $this->line("{$errors} error, {$warns} peringatan.");

        $failOn = $this->option('fail-on');
        $gagal = ($failOn === 'error' && $errors > 0) || ($failOn === 'warn' && ($errors + $warns) > 0);

        if ($gagal) {
            $this->error('Validasi GAGAL: bereskan temuan di atas sebelum cutover.');

            return self::FAILURE;
        }

        $this->info('Validasi lolos.');

        return self::SUCCESS;
    }
}
