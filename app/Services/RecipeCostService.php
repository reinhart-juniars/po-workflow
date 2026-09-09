<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Support\Collection;

/**
 * Perhitungan HPP dan kebutuhan bahan dari resep.
 *
 * Menggantikan HPP residual (Saldo Awal + Beli - Opname) dengan angka yang
 * benar-benar berasal dari rincian bahan. Selama Phase 2-3 keduanya berjalan
 * berdampingan sebagai pembanding, sesuai kesepakatan di PRD.
 *
 * Tiga hal dijaga di sini:
 *
 * 1. Baris yang tidak bisa dihitung tidak pernah dianggap berbiaya nol tanpa
 *    jejak. Setiap kegagalan dicatat di 'issues' dan menaikkan bendera, karena
 *    HPP yang diam-diam kekecilan jauh lebih berbahaya daripada HPP yang
 *    ditandai tidak lengkap.
 * 2. Konversi satuan hanya dilakukan bila sah -- lewat registri satuan, atau
 *    lewat aturan konversi per bahan bila keduanya beda besaran. Sisanya
 *    ditandai, tidak ditebak.
 * 3. Resep berputar (A memakai B, B memakai A) dideteksi dan dihentikan.
 *    Tanpa penjagaan ini perhitungannya tidak pernah selesai.
 */
class RecipeCostService
{
    /** @var array<int, array<string, mixed>> Hasil per resep dalam satu pemanggilan. */
    protected array $memo = [];

    public function __construct(
        protected IngredientUnitConverter $converter
    ) {}

    /**
     * Biaya satu kali produksi resep, beserta rincian tiap barisnya.
     *
     * @return array<string, mixed>
     */
    public function cost(Recipe $recipe): array
    {
        $this->memo = [];

        return $this->costFor($recipe, []);
    }

    /**
     * Kebutuhan bahan untuk memproduksi sejumlah hasil.
     *
     * @param  float  $quantity  Jumlah yang ingin diproduksi, dalam satuan hasil resep.
     * @return array{rows: Collection<int, array<string, mixed>>, total_cost: float, issues: array<int, string>, has_cycle: bool, has_unmatched: bool}
     */
    public function requirements(Recipe $recipe, float $quantity): array
    {
        $yield = (float) $recipe->yield_qty;

        // Resep tanpa hasil tidak bisa diskalakan; membaginya akan menghasilkan
        // kebutuhan tak hingga, jadi dihentikan di sini dengan pesan yang jelas.
        if ($yield <= 0) {
            return [
                'rows' => collect(),
                'total_cost' => 0.0,
                'issues' => ['Resep "'.$recipe->name.'" tidak punya jumlah hasil (yield), kebutuhan bahan tidak bisa dihitung.'],
                'has_cycle' => false,
                'has_unmatched' => false,
            ];
        }

        $this->memo = [];
        $accumulator = [];
        $issues = [];
        $flags = ['cycle' => false, 'unmatched' => false];

        $this->accumulate($recipe, $quantity / $yield, [], $accumulator, $issues, $flags);

        $rows = collect($accumulator)
            ->sortByDesc('total_cost')
            ->values();

        return [
            'rows' => $rows,
            'total_cost' => round((float) $rows->sum('total_cost'), 2),
            'issues' => array_values(array_unique($issues)),
            'has_cycle' => $flags['cycle'],
            'has_unmatched' => $flags['unmatched'],
        ];
    }

    /**
     * @param  array<int, int>  $visiting  Rantai resep yang sedang dihitung, untuk deteksi putaran.
     * @return array<string, mixed>
     */
    protected function costFor(Recipe $recipe, array $visiting): array
    {
        if (array_key_exists($recipe->id, $this->memo)) {
            return $this->memo[$recipe->id];
        }

        $visiting[] = $recipe->id;

        $items = $recipe->relationLoaded('items')
            ? $recipe->items
            : $recipe->items()->with(['inventoryItem', 'refRecipe'])->get();

        $lines = [];
        $issues = [];
        $hasCycle = false;
        $hasUnmatched = false;

        foreach ($items as $item) {
            $line = $this->lineCost($item, $visiting);

            if ($line['issue'] !== null) {
                $issues[] = $line['issue'];
            }

            foreach ($line['nested_issues'] as $nested) {
                $issues[] = $nested;
            }

            $hasCycle = $hasCycle || $line['cycle'];
            $hasUnmatched = $hasUnmatched || $line['unmatched'];

            $lines[] = $line;
        }

        $yield = max((float) $recipe->yield_qty, 0.0);
        $totalRaw = round(array_sum(array_column($lines, 'line_total')), 2);

        // Menu tanpa rincian bahan tetapi punya angka dari Excel: dipakai sebagai
        // cadangan, dan ditandai supaya tidak tertukar dengan HPP hasil hitungan.
        $usesSnapshot = $lines === [] && $recipe->snapshot_hpp !== null;

        if ($usesSnapshot) {
            $hppPerYield = (float) $recipe->snapshot_hpp;
            $totalRaw = round($hppPerYield * ($yield ?: 1), 2);
        } else {
            $hppPerYield = $yield > 0 ? round($totalRaw / $yield, 2) : 0.0;

            if ($yield <= 0) {
                $issues[] = 'Resep "'.$recipe->name.'" tidak punya jumlah hasil (yield).';
            }
        }

        $ohc = $usesSnapshot && $recipe->snapshot_ohc !== null
            ? (float) $recipe->snapshot_ohc
            : round($hppPerYield * (float) $recipe->ohc_pct, 2);

        $profit = $usesSnapshot && $recipe->snapshot_profit !== null
            ? (float) $recipe->snapshot_profit
            : round(($hppPerYield + $ohc) * (float) $recipe->profit_pct, 2);

        $hargaJual = round($hppPerYield + $ohc + $profit, 2);

        // Evaluasi terhadap harga jual nyata, mengikuti cara Master Menu Revamp
        // membandingkan hitungan dengan target: kalau target ada, itulah yang
        // dipakai mengukur, bukan harga hasil hitungan sendiri.
        $pakaiTarget = $recipe->target_price !== null;
        $hargaJualDipakai = $pakaiTarget ? (float) $recipe->target_price : $hargaJual;
        $totalBiaya = round($hppPerYield + $ohc, 2);
        $profitAktual = round($hargaJualDipakai - $totalBiaya, 2);

        $result = [
            'recipe_id' => $recipe->id,
            'name' => $recipe->name,
            'yield_qty' => $yield,
            'yield_unit' => $recipe->yield_unit,
            'lines' => $lines,
            'total_raw' => $totalRaw,
            'hpp_per_yield' => $hppPerYield,
            'ohc' => $ohc,
            'profit' => $profit,
            'harga_jual' => $hargaJual,
            'harga_jual_dipakai' => $hargaJualDipakai,
            'pakai_target' => $pakaiTarget,
            'total_biaya' => $totalBiaya,
            'profit_aktual' => $profitAktual,
            'profit_pct_aktual' => $totalBiaya > 0 ? round($profitAktual / $totalBiaya, 4) : 0.0,
            'margin_pct_aktual' => $hargaJualDipakai > 0 ? round($profitAktual / $hargaJualDipakai, 4) : 0.0,
            'profit_ok' => $totalBiaya > 0 && ($profitAktual / $totalBiaya) >= (float) $recipe->profit_pct,
            'uses_snapshot' => $usesSnapshot,
            'has_unmatched' => $hasUnmatched,
            'has_cycle' => $hasCycle,
            'issues' => array_values(array_unique($issues)),
        ];

        // Hasil resep yang mengandung putaran tidak di-memo: nilainya bergantung
        // pada jalur pemanggilan, bukan pada resepnya sendiri.
        if (! $hasCycle) {
            $this->memo[$recipe->id] = $result;
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $visiting
     * @return array<string, mixed>
     */
    protected function lineCost(RecipeItem $item, array $visiting): array
    {
        $qty = (float) $item->qty;

        $line = [
            'item_id' => $item->id,
            'raw_name' => $item->raw_name,
            'qty' => $qty,
            'unit' => $item->unit,
            'source' => 'unmatched',
            'unit_price' => null,
            'line_total' => 0.0,
            'unmatched' => false,
            'cycle' => false,
            'issue' => null,
            // Masalah yang datang dari dalam sub-resep. Tanpa ini, resep induk
            // hanya menyalakan bendera tanpa memberi tahu apa yang salah.
            'nested_issues' => [],
        ];

        // 1. Baris menunjuk sub-resep: biayanya dihitung dari resep itu.
        if ($item->ref_recipe_id !== null) {
            return $this->subRecipeLine($item, $line, $qty, $visiting);
        }

        // 2. Baris menunjuk bahan.
        if ($item->inventory_item_id !== null) {
            return $this->ingredientLine($item, $line, $qty);
        }

        // 3. Belum cocok: pakai harga cadangan bila ada, dan selalu ditandai.
        $line['unmatched'] = true;
        $snapshot = $item->unit_price_snapshot !== null ? (float) $item->unit_price_snapshot : null;

        if ($snapshot !== null) {
            $line['unit_price'] = $snapshot;
            $line['line_total'] = round($qty * $snapshot, 2);
            $line['source'] = 'snapshot';
            $line['issue'] = 'Bahan "'.$item->raw_name.'" belum ditautkan; memakai harga cadangan.';

            return $line;
        }

        $line['issue'] = 'Bahan "'.$item->raw_name.'" belum ditautkan dan tidak punya harga; dihitung nol.';

        return $line;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<int, int>  $visiting
     * @return array<string, mixed>
     */
    protected function subRecipeLine(RecipeItem $item, array $line, float $qty, array $visiting): array
    {
        $line['source'] = 'recipe';

        /** @var Recipe|null $sub */
        $sub = $item->relationLoaded('refRecipe') ? $item->refRecipe : $item->refRecipe()->first();

        if (! $sub) {
            $line['unmatched'] = true;
            $line['issue'] = 'Sub-resep untuk "'.$item->raw_name.'" sudah tidak ada.';

            return $line;
        }

        // Putaran resep: A memakai B yang memakai A. Dihentikan di sini, karena
        // dibiarkan berlanjut perhitungannya tidak akan pernah selesai.
        if (in_array($sub->id, $visiting, true)) {
            $line['cycle'] = true;
            $line['issue'] = 'Resep berputar terdeteksi pada "'.$sub->name.'"; biayanya tidak dihitung.';

            return $line;
        }

        $subCost = $this->costFor($sub, $visiting);
        $subHpp = (float) $subCost['hpp_per_yield'];

        $line['nested_issues'] = $subCost['issues'];

        if ($subCost['has_cycle']) {
            $line['cycle'] = true;
        }

        if ($subCost['has_unmatched']) {
            $line['unmatched'] = true;
        }

        // Kuantitas baris dinyatakan dalam satuan hasil sub-resep. Bila
        // satuannya berbeda tetapi sebesaran, dikonversi; bila tidak, ditandai.
        $converted = $this->convertQuantity($qty, $item->unit, $sub->yield_unit);

        if ($converted === null) {
            $line['issue'] = 'Satuan "'.$item->unit.'" pada "'.$item->raw_name.'" tidak sepadan dengan satuan hasil sub-resep "'.$sub->yield_unit.'".';

            return $line;
        }

        $line['unit_price'] = $subHpp;
        $line['line_total'] = round($converted * $subHpp, 2);

        return $line;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    protected function ingredientLine(RecipeItem $item, array $line, float $qty): array
    {
        $line['source'] = 'ingredient';

        /** @var InventoryItem|null $ingredient */
        $ingredient = $item->relationLoaded('inventoryItem') ? $item->inventoryItem : $item->inventoryItem()->first();

        if (! $ingredient) {
            $line['unmatched'] = true;
            $line['issue'] = 'Bahan untuk "'.$item->raw_name.'" sudah tidak ada.';

            return $line;
        }

        $price = $ingredient->effectiveUnitPrice();

        if ($price === null) {
            $line['issue'] = 'Harga bahan "'.$ingredient->name.'" belum diisi; baris ini dihitung nol.';

            return $line;
        }

        $converted = $this->convertQuantity($qty, $item->unit, $ingredient->unit, $ingredient->id);

        if ($converted === null) {
            $line['unit_price'] = $price;
            $line['issue'] = 'Satuan "'.$item->unit.'" pada "'.$item->raw_name.'" tidak bisa dikonversi ke satuan harga "'.$ingredient->unit.'". Tambahkan aturan konversi untuk bahan "'.$ingredient->name.'".';

            return $line;
        }

        $line['unit_price'] = $price;
        $line['line_total'] = round($converted * $price, 2);

        return $line;
    }

    /**
     * Ubah kuantitas dari satuan baris ke satuan tujuan.
     *
     * Mengembalikan null bila tidak sah, supaya pemanggil menandainya alih-alih
     * memakai angka yang salah. Konversi lintas-besaran (gr -> pcs) hanya
     * berhasil bila bahannya punya aturan konversi -- entah milik bahan itu
     * sendiri, entah aturan umum.
     */
    protected function convertQuantity(float $qty, ?string $fromUnit, ?string $toUnit, ?int $inventoryItemId = null): ?float
    {
        return $this->converter->convert($qty, $fromUnit, $toUnit, $inventoryItemId);
    }

    /**
     * Telusuri resep sampai ke bahan terdalam dan jumlahkan kebutuhannya.
     *
     * @param  array<int, int>  $visiting
     * @param  array<int, array<string, mixed>>  $accumulator
     * @param  array<int, string>  $issues
     * @param  array{cycle: bool, unmatched: bool}  $flags
     */
    protected function accumulate(
        Recipe $recipe,
        float $multiplier,
        array $visiting,
        array &$accumulator,
        array &$issues,
        array &$flags
    ): void {
        if (in_array($recipe->id, $visiting, true)) {
            $flags['cycle'] = true;
            $issues[] = 'Resep berputar terdeteksi pada "'.$recipe->name.'"; penelusuran dihentikan.';

            return;
        }

        $visiting[] = $recipe->id;

        $items = $recipe->items()->with(['inventoryItem', 'refRecipe'])->get();

        foreach ($items as $item) {
            $qty = (float) $item->qty * $multiplier;

            if ($item->ref_recipe_id !== null) {
                $sub = $item->refRecipe;

                if (! $sub) {
                    $flags['unmatched'] = true;
                    $issues[] = 'Sub-resep untuk "'.$item->raw_name.'" sudah tidak ada.';

                    continue;
                }

                $subYield = (float) $sub->yield_qty;

                if ($subYield <= 0) {
                    $issues[] = 'Sub-resep "'.$sub->name.'" tidak punya jumlah hasil (yield).';

                    continue;
                }

                $converted = $this->convertQuantity($qty, $item->unit, $sub->yield_unit);

                if ($converted === null) {
                    $issues[] = 'Satuan "'.$item->unit.'" pada "'.$item->raw_name.'" tidak sepadan dengan satuan hasil sub-resep "'.$sub->yield_unit.'".';

                    continue;
                }

                $this->accumulate($sub, $converted / $subYield, $visiting, $accumulator, $issues, $flags);

                continue;
            }

            if ($item->inventory_item_id === null || ! $item->inventoryItem) {
                $flags['unmatched'] = true;
                $issues[] = 'Bahan "'.$item->raw_name.'" belum ditautkan; tidak masuk hitungan kebutuhan.';

                continue;
            }

            $ingredient = $item->inventoryItem;
            $converted = $this->convertQuantity($qty, $item->unit, $ingredient->unit, $ingredient->id);

            if ($converted === null) {
                $issues[] = 'Satuan "'.$item->unit.'" pada "'.$item->raw_name.'" tidak bisa dikonversi ke satuan bahan "'.$ingredient->unit.'". Tambahkan aturan konversi untuk bahan "'.$ingredient->name.'".';

                continue;
            }

            $price = $ingredient->effectiveUnitPrice();
            $key = $ingredient->id;

            if (! isset($accumulator[$key])) {
                $accumulator[$key] = [
                    'inventory_item_id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'unit' => $ingredient->unit,
                    'qty' => 0.0,
                    'unit_price' => $price,
                    'total_cost' => 0.0,
                ];
            }

            $accumulator[$key]['qty'] = round($accumulator[$key]['qty'] + $converted, 4);
            $accumulator[$key]['total_cost'] = round($accumulator[$key]['qty'] * (float) ($price ?? 0), 2);

            if ($price === null) {
                $issues[] = 'Harga bahan "'.$ingredient->name.'" belum diisi; biayanya dihitung nol.';
            }
        }
    }
}
