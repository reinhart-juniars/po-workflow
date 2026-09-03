<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Menyalin database kerja ke database staging.
 *
 * Dipakai berulang selama proyek Modul Inventory Terpadu: setiap migrasi baru
 * diuji di staging lebih dulu, dan staging perlu disegarkan dari data nyata
 * supaya hasil ujinya berarti. Database sumber hanya dibaca.
 */
class CloneDatabaseToStagingCommand extends Command
{
    protected $signature = 'db:clone-to-staging
        {--target= : Nama database tujuan (default: <database sumber>_staging)}
        {--keep-dump : Simpan file dump setelah selesai}
        {--force : Lewati konfirmasi}
        {--verify-only : Hanya bandingkan jumlah baris, tanpa dump & restore}';

    protected $description = 'Salin database kerja ke database staging, lalu verifikasi jumlah baris tiap tabel';

    public function handle(DatabaseBackupService $backup): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->error('Perintah ini tidak boleh dijalankan di environment production.');

            return self::FAILURE;
        }

        $connection = DB::connection();
        $source = $connection->getDatabaseName();
        $target = (string) ($this->option('target') ?: $source.'_staging');

        // Pagar utama: perintah ini menimpa seluruh isi database tujuan, jadi
        // tujuan tidak boleh sama dengan sumber dalam keadaan apa pun.
        if ($target === $source) {
            $this->error("Database tujuan sama dengan sumber ({$source}). Dibatalkan.");

            return self::FAILURE;
        }

        $this->line("Sumber : {$source}");
        $this->line("Tujuan : {$target}");

        // Mode periksa: berguna untuk mendeteksi staging yang sudah menyimpang
        // dari data kerja tanpa harus menyalin ulang seluruh database.
        if ($this->option('verify-only')) {
            return $this->reportVerification($this->verifyRowCounts($source, $target), $source, $target);
        }

        if (! $this->option('force') && ! $this->confirm("Seluruh isi {$target} akan ditimpa. Lanjutkan?", false)) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        $dumpPath = storage_path('app/private/clone-'.$target.'-'.now()->format('Ymd_His').'.sql');

        try {
            $this->components->task("Dump {$source}", function () use ($backup, $dumpPath) {
                if (! is_dir(dirname($dumpPath))) {
                    mkdir(dirname($dumpPath), 0775, true);
                }

                $handle = fopen($dumpPath, 'wb');

                if ($handle === false) {
                    throw new RuntimeException('Tidak bisa menulis file dump: '.$dumpPath);
                }

                try {
                    $backup->dump(function (string $chunk) use ($handle) {
                        fwrite($handle, $chunk);
                    });
                } finally {
                    fclose($handle);
                }
            });

            $this->components->task("Restore ke {$target}", fn () => $this->restoreInto($target, $dumpPath, $backup));

            $mismatches = $this->verifyRowCounts($source, $target);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (! $this->option('keep-dump') && is_file($dumpPath)) {
                @unlink($dumpPath);
            }
        }

        if ($this->reportVerification($mismatches, $source, $target) === self::FAILURE) {
            return self::FAILURE;
        }

        if ($this->option('keep-dump')) {
            $this->line('Dump disimpan: '.$dumpPath);
        }

        return self::SUCCESS;
    }

    /** Cetak hasil perbandingan jumlah baris dan tentukan exit code. */
    protected function reportVerification(\Illuminate\Support\Collection $mismatches, string $source, string $target): int
    {
        $this->newLine();

        if ($mismatches->isNotEmpty()) {
            $this->error('Jumlah baris tidak cocok pada '.$mismatches->count().' tabel:');
            $this->table(['Tabel', 'Sumber', 'Tujuan'], $mismatches->all());

            return self::FAILURE;
        }

        $this->info("Selesai. {$target} identik dengan {$source} pada seluruh tabel.");

        return self::SUCCESS;
    }

    /**
     * Restore dump ke database tujuan.
     *
     * DatabaseBackupService menulis lewat koneksi default, jadi default ditukar
     * sementara ke koneksi tujuan dan dikembalikan lagi apa pun hasilnya.
     */
    protected function restoreInto(string $target, string $dumpPath, DatabaseBackupService $backup): void
    {
        $previousDefault = Config::get('database.default');

        Config::set('database.connections.'.self::cloneConnection(), array_merge(
            Config::get('database.connections.'.$previousDefault),
            ['database' => $target]
        ));
        Config::set('database.default', self::cloneConnection());
        DB::purge(self::cloneConnection());

        try {
            $backup->restore($dumpPath);
        } finally {
            Config::set('database.default', $previousDefault);
            DB::purge(self::cloneConnection());
        }
    }

    /**
     * Bandingkan jumlah baris tiap tabel sumber dengan tujuan.
     *
     * Restore yang gagal separuh jalan tetap meninggalkan database yang bisa
     * dibuka, jadi keberhasilan tidak boleh disimpulkan dari tidak adanya
     * exception saja.
     *
     * @return \Illuminate\Support\Collection<int, array{0: string, 1: int, 2: int}>
     */
    protected function verifyRowCounts(string $source, string $target): \Illuminate\Support\Collection
    {
        $targetConnection = $this->targetConnection($target);

        $tables = collect(DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'))
            ->map(fn ($row) => array_values((array) $row)[0]);

        $mismatches = collect();

        $this->components->task('Verifikasi '.$tables->count().' tabel', function () use ($tables, $targetConnection, $mismatches) {
            foreach ($tables as $table) {
                $sourceCount = (int) DB::table($table)->count();
                $targetCount = (int) $targetConnection->table($table)->count();

                if ($sourceCount !== $targetCount) {
                    $mismatches->push([$table, $sourceCount, $targetCount]);
                }
            }

            return $mismatches->isEmpty();
        });

        return $mismatches;
    }

    protected function targetConnection(string $target): \Illuminate\Database\Connection
    {
        Config::set('database.connections.'.self::verifyConnection(), array_merge(
            Config::get('database.connections.'.Config::get('database.default')),
            ['database' => $target]
        ));

        DB::purge(self::verifyConnection());

        return DB::connection(self::verifyConnection());
    }

    protected static function cloneConnection(): string
    {
        return 'clone_target';
    }

    protected static function verifyConnection(): string
    {
        return 'clone_verify';
    }
}
