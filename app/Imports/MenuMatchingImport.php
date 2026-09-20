<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Unggah balik lembar kerja Pencocokan Menu (format hasil Export).
 *
 * Tiap baris adalah satu produk (produk_id) dengan paling banyak satu
 * keputusan: terima_usulan, resep tertentu (resep_id / nama_resep), atau
 * tanpa_resep. Baris tanpa keputusan -- atau yang keputusannya sama dengan
 * keadaan sekarang -- dilewati, sehingga berkas boleh diunggah bertahap.
 *
 * Import tidak pernah MELEPAS tautan: mengosongkan resep_id di Excel tidak
 * berarti "lepaskan", karena kolom itu juga kosong pada setiap produk yang
 * memang belum dicocokkan. Melepas dilakukan dari layar, satu per satu.
 *
 * Satu kesalahan membatalkan seluruh berkas, supaya staf memperbaiki berkasnya
 * dan mengunggah ulang, bukan menebak baris mana yang sempat tersimpan.
 */
class MenuMatchingImport implements SkipsEmptyRows, ToCollection, WithHeadingRow, WithMultipleSheets
{
    protected int $linked = 0;

    protected int $withoutRecipe = 0;

    protected int $skipped = 0;

    /** @var array<int, string> */
    protected array $errors = [];

    /** Hanya sheet pertama yang berisi keputusan; sheet "Daftar Resep" rujukan saja. */
    public function sheets(): array
    {
        return [0 => $this];
    }

    public function collection(Collection $rows): void
    {
        $products = Product::query()->get(['id', 'name', 'recipe_id', 'needs_recipe'])->keyBy('id');
        $recipes = Recipe::query()->get(['id', 'name', 'name_norm', 'is_active']);
        $recipesById = $recipes->keyBy('id');
        $recipesByNorm = $recipes->keyBy('name_norm');

        /** @var array<int, array{recipe_id: int|null, needs_recipe: bool}> */
        $decisions = [];

        foreach ($rows as $index => $row) {
            // +2: baris pertama adalah heading, dan penomoran spreadsheet mulai dari 1.
            $line = $index + 2;
            $productId = (int) ($row['produk_id'] ?? 0);
            $product = $products->get($productId);

            if (! $product) {
                $this->errors[] = "Baris {$line}: produk_id '".($row['produk_id'] ?? '')."' tidak ditemukan.";

                continue;
            }

            if (isset($decisions[$productId])) {
                $this->errors[] = "Baris {$line}: produk '{$product->name}' muncul dua kali.";

                continue;
            }

            $tanpa = $this->isYes($row['tanpa_resep'] ?? null);
            $terima = $this->isYes($row['terima_usulan'] ?? null);
            $recipeId = filled($row['resep_id'] ?? null) ? (int) $row['resep_id'] : null;
            $recipeName = trim((string) ($row['nama_resep'] ?? ''));

            // Resep tertentu: id menang atas nama; nama dicocokkan persis
            // (ternormalisasi), bukan dikira-kira -- menebak resep = menebak HPP.
            $target = null;

            if ($recipeId !== null) {
                $target = $recipesById->get($recipeId);

                if (! $target) {
                    $this->errors[] = "Baris {$line}: resep_id '{$recipeId}' tidak ditemukan.";

                    continue;
                }
            } elseif ($recipeName !== '') {
                $target = $recipesByNorm->get(Recipe::normalizeName($recipeName));

                if (! $target) {
                    $this->errors[] = "Baris {$line}: resep '{$recipeName}' tidak ditemukan; pakai nama persis dari sheet Daftar Resep atau isi resep_id.";

                    continue;
                }
            }

            if ($terima) {
                $usulanId = (int) ($row['usulan_resep_id'] ?? 0);
                $usulan = $recipesById->get($usulanId);

                if (! $usulan) {
                    $this->errors[] = "Baris {$line}: terima_usulan diisi tetapi usulan_resep_id kosong atau tidak dikenal.";

                    continue;
                }

                if ($target !== null && $target->id !== $usulan->id) {
                    $this->errors[] = "Baris {$line}: terima_usulan bertentangan dengan resep yang diisi ('{$target->name}').";

                    continue;
                }

                $target = $usulan;
            }

            if ($tanpa && $target !== null && $target->id !== $product->recipe_id) {
                $this->errors[] = "Baris {$line}: tanpa_resep = ya tetapi resep juga diisi; pilih salah satu.";

                continue;
            }

            if ($tanpa) {
                if (! $product->needs_recipe && $product->recipe_id === null) {
                    $this->skipped++;

                    continue;
                }

                $decisions[$productId] = ['recipe_id' => null, 'needs_recipe' => false];

                continue;
            }

            if ($target === null || $target->id === $product->recipe_id) {
                $this->skipped++;

                continue;
            }

            if (! $target->is_active) {
                $this->errors[] = "Baris {$line}: resep '{$target->name}' nonaktif; SPK Produksi akan mengabaikannya.";

                continue;
            }

            $decisions[$productId] = ['recipe_id' => $target->id, 'needs_recipe' => true];
        }

        if ($this->errors !== []) {
            return;
        }

        DB::transaction(function () use ($decisions) {
            foreach ($decisions as $productId => $decision) {
                Product::query()->whereKey($productId)->update($decision);

                $decision['recipe_id'] === null ? $this->withoutRecipe++ : $this->linked++;
            }
        });
    }

    protected function isYes(mixed $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['ya', 'y', 'yes', '1', 'true', 'v', 'x'], true);
    }

    public function linked(): int
    {
        return $this->linked;
    }

    public function withoutRecipe(): int
    {
        return $this->withoutRecipe;
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
