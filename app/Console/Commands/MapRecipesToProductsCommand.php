<?php

namespace App\Console\Commands;

use App\Models\Recipe;
use App\Services\MasterMenu\MasterMenuAuditService;
use App\Services\MasterMenu\MasterMenuSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Terapkan pemetaan resep -> produk yang cocok persis atau cocok setelah
 * normalisasi nama (skor 100 pada audit).
 *
 * Hanya pasangan yang tidak butuh penilaian manusia yang ditulis; 352 sisanya
 * tetap diputuskan bersama klien lewat tab "Belum Dipetakan" pada Resep.
 * Resep yang sudah punya produk tidak disentuh, dan satu produk tidak boleh
 * jadi milik dua resep -- kalau terjadi, keduanya dilaporkan, bukan ditebak.
 */
class MapRecipesToProductsCommand extends Command
{
    protected $signature = 'inventory:map-recipes-to-products
        {--db= : Path app.db Master Menu (default: config master_menu.database)}
        {--database= : Koneksi tujuan (mis. mysql_staging)}
        {--dry-run : Tampilkan yang akan dipetakan tanpa menyimpan}';

    protected $description = 'Petakan resep ke produk penjualan untuk pasangan yang namanya cocok persis (skor 100)';

    public function handle(): int
    {
        try {
            $source = new MasterMenuSource($this->option('db'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($connection = $this->option('database')) {
            if (! config()->has('database.connections.'.$connection)) {
                $this->error("Koneksi '{$connection}' tidak terdaftar.");

                return self::FAILURE;
            }

            config(['database.default' => $connection]);
            DB::purge($connection);
        }

        $mapping = (new MasterMenuAuditService($source))->menuMapping()
            ->whereIn('status', ['cocok_persis', 'cocok_normalisasi'])
            ->whereNotNull('product_id');

        $recipesBySource = Recipe::query()->whereNotNull('source_recipe_id')->get()->keyBy('source_recipe_id');
        $takenProducts = Recipe::query()->whereNotNull('product_id')->pluck('id', 'product_id');

        $applied = [];
        $skipped = [];
        $plannedProducts = [];

        foreach ($mapping as $row) {
            $recipe = $recipesBySource->get($row['recipe_id']);

            if (! $recipe) {
                $skipped[] = [$row['nama_resep'], 'resep belum dipindahkan ke po-workflow'];

                continue;
            }

            if ($recipe->product_id !== null) {
                $skipped[] = [$recipe->name, 'sudah dipetakan; tidak disentuh'];

                continue;
            }

            $owner = $takenProducts[$row['product_id']] ?? $plannedProducts[$row['product_id']] ?? null;

            if ($owner !== null && $owner !== $recipe->id) {
                $skipped[] = [$recipe->name, 'produk '.$row['product_name'].' sudah dipakai resep lain; putuskan manual'];

                continue;
            }

            $plannedProducts[$row['product_id']] = $recipe->id;
            $applied[] = ['recipe' => $recipe, 'product_id' => $row['product_id'], 'product_name' => $row['product_name'], 'status' => $row['status']];
        }

        $this->table(['Resep', 'Produk', 'Cara cocok'], array_map(
            fn (array $a) => [$a['recipe']->name, $a['product_name'], $a['status'] === 'cocok_persis' ? 'persis' : 'normalisasi'],
            $applied,
        ));

        if ($skipped !== []) {
            $this->newLine();
            $this->warn(count($skipped).' dilewati:');
            $this->table(['Resep', 'Alasan'], $skipped);
        }

        if ($this->option('dry-run')) {
            $this->warn('Uji-jalan: '.count($applied).' pemetaan akan diterapkan. Tidak ada yang disimpan.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($applied) {
            foreach ($applied as $a) {
                $a['recipe']->update(['product_id' => $a['product_id']]);
            }
        });

        $this->info(count($applied).' resep dipetakan ke produk.');

        return self::SUCCESS;
    }
}
