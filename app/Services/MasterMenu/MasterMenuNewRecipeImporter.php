<?php

namespace App\Services\MasterMenu;

use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\RecipeMismatchResolver;
use App\Support\Units\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tambahkan resep Master Menu Revamp yang belum ada di 3S ONE -- hanya itu.
 *
 * Berbeda dari MasterMenuMigrationService, yang menyusun ulang seluruh baris
 * resep dan menimpa bahan setiap kali dijalankan. Sejak tim menu bekerja di
 * 3S ONE (menautkan bahan, membetulkan satuan, mengubah takaran), penyegaran
 * penuh seperti itu menghapus pekerjaan mereka. Sementara itu klien masih
 * menulis resep baru di aplikasi Master Menu, dan resep itu dibutuhkan di
 * Pencocokan Menu.
 *
 * Aturannya: resep dan bahan yang sudah ada (dikenali dari id sumbernya) tidak
 * disentuh sama sekali, termasuk bila isinya di Master Menu sudah berubah.
 * Yang ditambahkan hanya resep baru beserta barisnya, dan bahan baru yang
 * dipakai resep-resep itu.
 */
class MasterMenuNewRecipeImporter
{
    /** Tabel yang harus ada supaya berkas bisa dianggap database Master Menu. */
    public const REQUIRED_TABLES = ['ingredients', 'recipes', 'recipe_items'];

    public function __construct(
        protected MasterMenuSource $source,
        protected MasterMenuMigrationService $mapper,
    ) {}

    public static function forFile(string $path): self
    {
        $source = new MasterMenuSource($path);

        return new self($source, new MasterMenuMigrationService($source));
    }

    /** Pastikan berkasnya database SQLite Master Menu, dengan pesan yang bisa dibaca orang. */
    public function assertReadable(): void
    {
        $handle = @fopen($this->source->path(), 'rb');
        $header = $handle ? fread($handle, 16) : '';

        if ($handle) {
            fclose($handle);
        }

        if ($header !== "SQLite format 3\0") {
            throw new RuntimeException('Berkas ini bukan database Master Menu. Pilih berkas app.db dari folder Master Menu Revamp/app/data.');
        }

        $tables = collect($this->source->connection()->select("SELECT name FROM sqlite_master WHERE type = 'table'"))
            ->pluck('name');
        $missing = collect(self::REQUIRED_TABLES)->diff($tables);

        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Berkas ini bukan database Master Menu (tabel '.$missing->implode(', ').' tidak ada).');
        }
    }

    /**
     * Jalankan impor, atau uji-jalan untuk pratinjau.
     *
     * @return array{
     *     resep_baru: list<string>,
     *     resep_sudah_ada: int,
     *     bahan_baru: list<array{name: string, unit: string, perlu_cek: bool}>,
     *     baris_resep: int,
     *     baris_belum_tertaut: int,
     *     baris_tertaut_keputusan: int
     * }
     */
    public function run(bool $dryRun = false, ?int $userId = null): array
    {
        try {
            return $this->runOnce($dryRun, $userId);
        } finally {
            $this->source->disconnect();
        }
    }

    /** @return array<string, mixed> */
    protected function runOnce(bool $dryRun, ?int $userId): array
    {
        $this->assertReadable();

        $summary = [];

        try {
            DB::transaction(function () use ($dryRun, $userId, &$summary) {
                $summary = $this->import($userId);

                if ($dryRun) {
                    // Pola yang sama dengan MasterMenuMigrationService: jalur tulis
                    // ditempuh penuh supaya pratinjau = hasil sungguhan, lalu
                    // dibatalkan lewat exception (bukan DB::rollBack manual).
                    throw new DryRunCompleted;
                }
            });
        } catch (DryRunCompleted) {
            // Dibatalkan; ringkasannya tetap dipakai sebagai pratinjau.
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    protected function import(?int $userId): array
    {
        $recipeBySource = Recipe::query()->whereNotNull('source_recipe_id')->pluck('id', 'source_recipe_id');
        $itemBySource = InventoryItem::query()->whereNotNull('source_ingredient_id')->pluck('id', 'source_ingredient_id');

        $sourceRecipes = $this->source->table('recipes')->orderBy('id')->get();
        $newRecipes = $sourceRecipes->reject(fn (object $row) => $recipeBySource->has($row->id));

        $lines = $this->source->table('recipe_items')
            ->whereIn('recipe_id', $newRecipes->pluck('id'))
            ->orderBy('recipe_id')->orderBy('sort_order')->orderBy('id')
            ->get();

        $newIngredients = $this->importIngredients($lines, $itemBySource);

        // Resep dibuat dulu seluruhnya, baru barisnya: baris boleh menunjuk
        // sub-resep yang juga baru di impor yang sama.
        foreach ($newRecipes as $row) {
            $recipe = Recipe::query()->create($this->mapper->recipeAttributes($row) + [
                'source_recipe_id' => $row->id,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $recipeBySource[$row->id] = $recipe->id;
        }

        $unlinked = 0;

        foreach ($lines as $row) {
            $inventoryItemId = $row->ingredient_id ? ($itemBySource[$row->ingredient_id] ?? null) : null;
            $refRecipeId = $row->ref_recipe_id ? ($recipeBySource[$row->ref_recipe_id] ?? null) : null;

            RecipeItem::query()->create($this->mapper->recipeItemAttributes(
                $row, (int) $recipeBySource[$row->recipe_id], $inventoryItemId, $refRecipeId,
            ));

            if ($inventoryItemId === null && $refRecipeId === null) {
                $unlinked++;
            }
        }

        // Keputusan Bahan Belum Cocok yang sudah diambil tim menu ("garam" ->
        // bahan Garam) ikut berlaku untuk baris resep baru; hanya baris yang
        // belum tertaut yang disentuh.
        $resolver = app(RecipeMismatchResolver::class);
        $reapplied = $newRecipes->isEmpty() ? ['baris' => 0] : $resolver->reapplyAll();

        if ($newRecipes->isNotEmpty()) {
            $resolver->rebuild();
        }

        return [
            'resep_baru' => $newRecipes->map(fn (object $row) => trim((string) $row->name))->values()->all(),
            'resep_sudah_ada' => $sourceRecipes->count() - $newRecipes->count(),
            'bahan_baru' => $newIngredients,
            'baris_resep' => $lines->count(),
            'baris_belum_tertaut' => max(0, $unlinked - $reapplied['baris']),
            'baris_tertaut_keputusan' => $reapplied['baris'],
        ];
    }

    /**
     * Bahan sumber yang dipakai resep baru tetapi belum ada di 3S ONE.
     *
     * Bahan yang sudah ada tidak disentuh. Satuan kosong di Master Menu tidak
     * lagi otomatis jadi "pcs" (itu asal kasus Sereh yang harganya per gram
     * tercatat per pcs): satuannya diambil dari baris resep bila semuanya
     * sepakat, dan sisanya ditandai perlu dicek.
     *
     * @param  Collection<int, object>  $lines
     * @param  Collection<int|string, int>  $itemBySource  Ditambah bahan yang baru dibuat.
     * @return list<array{name: string, unit: string, perlu_cek: bool}>
     */
    protected function importIngredients(Collection $lines, Collection $itemBySource): array
    {
        $neededIds = $lines->pluck('ingredient_id')->filter()->unique()
            ->reject(fn ($id) => $itemBySource->has($id))
            ->values();

        if ($neededIds->isEmpty()) {
            return [];
        }

        $created = [];

        foreach ($this->source->table('ingredients')->whereIn('id', $neededIds)->orderBy('id')->get() as $row) {
            $attributes = $this->mapper->ingredientAttributes($row);
            $needsCheck = false;

            if (blank($row->pack_unit)) {
                $lineUnits = $lines->where('ingredient_id', $row->id)
                    ->map(fn (object $line) => Unit::canonical($line->unit))
                    ->filter()
                    ->unique();

                $needsCheck = $lineUnits->count() !== 1;
                $attributes['unit'] = $needsCheck ? 'pcs' : $lineUnits->first();
            }

            $item = InventoryItem::withoutPriceHistory(fn () => InventoryItem::query()->create($attributes + ['source_ingredient_id' => $row->id]));
            $itemBySource[$row->id] = $item->id;

            $created[] = ['name' => $item->name, 'unit' => $item->unit, 'perlu_cek' => $needsCheck];
        }

        return $created;
    }
}
