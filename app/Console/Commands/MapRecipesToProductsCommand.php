<?php

namespace App\Console\Commands;

use App\Models\Product;
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
 * Hanya pasangan yang tidak butuh penilaian manusia yang ditulis; sisanya
 * diputuskan bersama klien lewat halaman Pencocokan Menu. Tautan disimpan di
 * sisi produk (`products.recipe_id`): produk yang sudah punya resep atau sudah
 * ditandai "tanpa resep" tidak disentuh, dan satu resep boleh dipakai beberapa
 * produk (varian harga).
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
        $products = Product::query()->get(['id', 'recipe_id', 'needs_recipe'])->keyBy('id');

        $applied = [];
        $skipped = [];
        $plannedProducts = [];

        foreach ($mapping as $row) {
            $recipe = $recipesBySource->get($row['recipe_id']);

            if (! $recipe) {
                $skipped[] = [$row['nama_resep'], 'resep belum dipindahkan ke po-workflow'];

                continue;
            }

            $product = $products->get($row['product_id']);

            if (! $product) {
                $skipped[] = [$recipe->name, 'produk '.$row['product_name'].' sudah tidak ada'];

                continue;
            }

            if ($product->recipe_id !== null || ! $product->needs_recipe) {
                $skipped[] = [$recipe->name, 'produk '.$row['product_name'].' sudah diputuskan; tidak disentuh'];

                continue;
            }

            if (isset($plannedProducts[$row['product_id']])) {
                $skipped[] = [$recipe->name, 'produk '.$row['product_name'].' juga cocok dengan resep lain; putuskan manual'];

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
                Product::query()->whereKey($a['product_id'])->update(['recipe_id' => $a['recipe']->id]);
            }
        });

        $this->info(count($applied).' produk ditautkan ke resep.');

        return self::SUCCESS;
    }
}
