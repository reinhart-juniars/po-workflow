<?php

namespace App\Console\Commands;

use App\Exports\MasterMenuAuditExport;
use App\Services\MasterMenu\MasterMenuAuditService;
use App\Services\MasterMenu\MasterMenuSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

/**
 * Audit rekonsiliasi Master Menu Revamp terhadap PO-Workflow.
 *
 * Dijalankan sebelum migrasi Modul Inventory Terpadu untuk memetakan menu,
 * bahan, dan baris resep yang belum nyambung. Read-only terhadap kedua sumber.
 */
class AuditMasterMenuCommand extends Command
{
    protected $signature = 'inventory:audit-master-menu
        {--db= : Path file app.db Master Menu (default: config master_menu.database)}
        {--out=master-menu-audit.xlsx : Nama file hasil, relatif ke disk local}
        {--no-export : Tampilkan ringkasan di terminal saja, tanpa menulis file}';

    protected $description = 'Audit rekonsiliasi data Master Menu Revamp vs PO-Workflow (menu, bahan, baris resep yatim)';

    public function handle(): int
    {
        try {
            $source = new MasterMenuSource($this->option('db'));
            $this->line('Sumber: '.$source->path());

            $audit = new MasterMenuAuditService($source);

            $summary = $audit->summary();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Gagal membaca database Master Menu: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Metrik', 'Nilai'], $summary->map(fn (array $r) => [$r['metrik'], $r['nilai']])->all());

        if ($this->option('no-export')) {
            return self::SUCCESS;
        }

        $sheets = [
            'Ringkasan' => $summary,
            'Mapping Menu' => $audit->menuMapping(),
            'Produk Tanpa Resep' => $audit->productsWithoutRecipe(),
            'Mapping Bahan' => $audit->ingredientMapping(),
            'Baris Resep Yatim' => $audit->orphanRecipeItems(),
        ];

        $path = (string) $this->option('out');
        Excel::store(new MasterMenuAuditExport($sheets), $path, 'local');

        $this->newLine();
        $this->info('Laporan audit tersimpan: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }
}
