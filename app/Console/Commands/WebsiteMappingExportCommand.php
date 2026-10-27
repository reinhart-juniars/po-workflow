<?php

namespace App\Console\Commands;

use App\Exports\WebsiteMappingExport;
use App\Services\WebsiteMenuMapper;
use App\Support\WebsiteMenuSource;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bagian C: lembar pemetaan hidangan website lama -> produk Master Menu.
 * Jalankan di server yang datanya dipakai (latihan / resmi) supaya ID produk
 * di lembar sama dengan ID saat diimpor kembali.
 */
class WebsiteMappingExportCommand extends Command
{
    protected $signature = 'website:mapping-export
        {--site= : Folder website lama (bawaan w3s-landing di repo)}
        {--output= : Lokasi berkas .xlsx (bawaan storage/app/pemetaan-menu-website-<tanggal>.xlsx)}';

    protected $description = 'Buat lembar Excel pemetaan menu website ke Master Menu (Bagian C)';

    public function handle(WebsiteMenuMapper $mapper): int
    {
        $site = $this->option('site') ?: base_path('w3s-landing');
        $output = $this->option('output') ?: storage_path('app/pemetaan-menu-website-'.now()->format('Ymd').'.xlsx');

        try {
            $dishes = WebsiteMenuSource::read($site);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = $mapper->suggest($dishes);
        file_put_contents($output, Excel::raw(new WebsiteMappingExport($rows, $mapper->productIndex()), ExcelFormat::XLSX));

        $this->info(count($rows).' hidangan ditulis ke '.$output);
        $this->table(['Status', 'Jumlah'], collect(WebsiteMenuMapper::STATUS_LABELS)
            ->map(fn ($label, $key) => [$label, collect($rows)->where('status', $key)->count()])->values()->all());

        return self::SUCCESS;
    }
}
