<?php

namespace App\Console\Commands;

use App\Services\WebsiteMappingImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Bagian C: terapkan lembar pemetaan menu website yang sudah diisi marketing.
 * Coba dulu dengan --dry-run; tanpa itu perubahan langsung disimpan.
 */
class WebsiteMappingImportCommand extends Command
{
    protected $signature = 'website:mapping-import
        {file : Berkas .xlsx hasil website:mapping-export yang sudah diisi}
        {--site= : Folder website lama untuk mengambil foto (bawaan w3s-landing di repo)}
        {--dry-run : Periksa saja, jangan simpan}';

    protected $description = 'Terapkan pemetaan menu website: SKU, Tampil di website, kategori, foto (Bagian C)';

    public function handle(WebsiteMappingImporter $importer): int
    {
        try {
            $result = $importer->check((string) $this->argument('file'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['errors'] !== []) {
            $this->error(count($result['errors']).' masalah; tidak ada yang disimpan:');
            foreach ($result['errors'] as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        if ($result['warnings'] !== []) {
            $this->warn(count($result['warnings']).' hidangan halamannya berbeda dengan tanda 3S produknya:');
            foreach ($result['warnings'] as $warning) {
                $this->line(' - '.$warning);
            }
        }

        $withoutPhoto = collect($result['rows'])->filter(fn ($row) => $row['product']->photo_path === null)->count();
        $this->info(count($result['rows']).' hidangan siap dipetakan, '.$result['skipped'].' dibiarkan kosong (tidak tampil di website baru).');
        $this->line("{$withoutPhoto} produk belum berfoto; yang punya foto di website lama memakai foto itu.");

        if ($this->option('dry-run')) {
            $this->comment('--dry-run: tidak ada yang disimpan.');

            return self::SUCCESS;
        }

        $summary = $importer->apply($result['rows'], $this->option('site') ?: base_path('w3s-landing'));

        $this->info("Tersimpan: {$summary['updated']} produk, {$summary['sku_changed']} SKU diganti, {$summary['photos']} foto dari website lama.");
        $this->line('Buka Marketing › Katalog Foto Menu untuk memeriksa, lalu tekan Terbitkan ke Website.');

        return self::SUCCESS;
    }
}
