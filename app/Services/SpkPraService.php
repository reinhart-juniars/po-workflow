<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderComponentOverride;
use App\Models\ProductionOrderLine;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\SpkComponentRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menyusun Lembar SPK PRA: dokumen per menu yang dikirim gudang ke produksi.
 *
 * Resep dipecah menjadi komponen, lalu tiap komponen ditampilkan sesuai setelannya
 * (SpkComponentRule): rinci, satu baris, atau disembunyikan. Komponen adalah bagian
 * resep (section) atau sub-menu; bahan tanpa bagian dikumpulkan di satu kelompok
 * tanpa judul. Sub-menu yang rinci ikut diurai dan dihitung otomatis.
 *
 * Bahan berkategori kemasan tidak pernah tampil karena lembar ini perintah masak.
 * Nama bahan memakai tulisan resep (raw_name), bukan nama item stok.
 *
 * Jumlah khusus per SPK menskalakan isi komponen:
 *   jumlah bahan = jumlah resep x (jumlah khusus / jumlah acuan komponen)
 * Contoh: Suun Kecap acuan 12,5 porsi diisi 13, suun 0,375 pack menjadi 0,39 pack.
 */
class SpkPraService
{
    /** Kunci kelompok bahan yang tidak punya bagian di resep. */
    public const KEY_LOOSE = '(tanpa bagian)';

    /** @var array<int, Collection<int, RecipeItem>> */
    protected array $items = [];

    /** @var array<string, SpkComponentRule> */
    protected array $rules = [];

    /** @var array<string, ProductionOrderComponentOverride> */
    protected array $overrides = [];

    public function __construct(
        protected IngredientUnitConverter $converter,
    ) {}

    /** @return array{order: ProductionOrder, menus: list<array<string, mixed>>} */
    public function forProductionOrder(ProductionOrder $order): array
    {
        $this->rules = SpkComponentRule::query()->get()
            ->keyBy(fn (SpkComponentRule $rule) => $this->ruleKey($rule->recipe_id, $rule->component_key))
            ->all();

        $this->overrides = ProductionOrderComponentOverride::query()
            ->where('production_order_id', $order->id)
            ->get()
            ->keyBy(fn ($override) => $override->recipe_id.'|'.$override->component_key)
            ->all();

        $menus = [];

        foreach ($this->groupLines($order) as $line) {
            $menus[] = array_merge(['no' => count($menus) + 1], $line['recipe'] ? $this->menu($line) : $this->manualMenu($line));
        }

        return ['order' => $order, 'menus' => $menus];
    }

    /**
     * Simpan setelan komponen. Setelan "semua menu" membuang pengecualian menu ini
     * supaya perubahannya langsung terlihat; setelan yang sama dengan bawaan tidak
     * perlu disimpan kecuali sebagai pengecualian dari aturan semua menu.
     *
     * @param  array{display: string, label?: ?string, basis_item?: ?string, hidden_items?: ?array<int, string>}  $data
     */
    public function saveRule(int $recipeId, string $key, bool $allMenus, array $data, ?int $userId): void
    {
        $basisItem = $data['display'] === SpkComponentRule::DISPLAY_SINGLE ? ($data['basis_item'] ?? null) : null;
        $hiddenItems = $data['display'] === SpkComponentRule::DISPLAY_DETAIL ? array_values($data['hidden_items'] ?? []) : [];

        $attributes = [
            'display' => $data['display'],
            'label' => filled($data['label'] ?? null) ? trim($data['label']) : null,
            'qty_basis' => $basisItem ? SpkComponentRule::BASIS_INGREDIENT : SpkComponentRule::BASIS_COMPONENT,
            'basis_item' => $basisItem,
            'hidden_items' => $hiddenItems === [] ? null : $hiddenItems,
            'updated_by' => $userId,
        ];

        $isDefault = $attributes['display'] === SpkComponentRule::DISPLAY_DETAIL
            && $attributes['label'] === null
            && $attributes['hidden_items'] === null;

        DB::transaction(function () use ($recipeId, $key, $allMenus, $attributes, $isDefault) {
            if ($allMenus) {
                SpkComponentRule::query()->where('recipe_id', $recipeId)->where('component_key', $key)->delete();
                $global = SpkComponentRule::query()->whereNull('recipe_id')->where('component_key', $key);

                if ($isDefault) {
                    $global->delete();

                    return;
                }

                if ($existing = $global->first()) {
                    $existing->update($attributes);

                    return;
                }

                SpkComponentRule::query()->create($attributes + ['recipe_id' => null, 'component_key' => $key]);

                return;
            }

            SpkComponentRule::query()->updateOrCreate(['recipe_id' => $recipeId, 'component_key' => $key], $attributes);
        });
    }

    /** Simpan jumlah khusus dan catatan satu SPK; dua-duanya kosong berarti kembali ke hitungan resep. */
    public function saveOverride(ProductionOrder $order, int $recipeId, string $key, ?float $qty, ?string $note, ?int $userId): void
    {
        $identity = ['production_order_id' => $order->id, 'recipe_id' => $recipeId, 'component_key' => $key];
        $note = filled($note) ? trim($note) : null;

        if ($qty === null && $note === null) {
            ProductionOrderComponentOverride::query()->where($identity)->delete();

            return;
        }

        ProductionOrderComponentOverride::query()->updateOrCreate($identity, ['qty' => $qty, 'note' => $note, 'updated_by' => $userId]);
    }

    /**
     * Baris menu yang sama digabung seperti Breakdown Bahan; baris manual tetap
     * berdiri sendiri.
     *
     * @return Collection<int, array{label: string, recipe: ?Recipe, qty: ?float, unit: ?string, note: ?string}>
     */
    protected function groupLines(ProductionOrder $order): Collection
    {
        return $order->lines()->with('recipe')->get()
            ->groupBy(fn (ProductionOrderLine $line) => $line->isMenu() ? 'r'.$line->recipe_id.'|'.mb_strtolower((string) $line->unit) : 'l'.$line->id)
            ->map(function (Collection $group) {
                /** @var ProductionOrderLine $first */
                $first = $group->first();

                return [
                    'label' => $first->recipe?->name ?? $first->displayName(),
                    'recipe' => $first->isMenu() ? $first->recipe : null,
                    'qty' => $first->qty === null ? null : (float) $group->sum('qty'),
                    'unit' => $first->unit ?: ($first->isMenu() ? 'porsi' : null),
                    'note' => $group->pluck('remark')->filter()->unique()->implode('; ') ?: null,
                ];
            })
            ->values();
    }

    /** @param  array<string, mixed>  $line */
    protected function manualMenu(array $line): array
    {
        return [
            'label' => $line['label'],
            'recipe_id' => null,
            'title' => $line['label'],
            'qty' => $line['qty'],
            'unit' => $line['unit'],
            'note' => $line['note'],
            'components' => [],
            'hidden' => [],
            'issues' => [],
        ];
    }

    /** @param  array<string, mixed>  $line */
    protected function menu(array $line): array
    {
        /** @var Recipe $recipe */
        $recipe = $line['recipe'];
        $issues = [];
        $yield = (float) $recipe->yield_qty;
        $quantity = $this->converter->convertByRegistry((float) $line['qty'], $line['unit'], $recipe->yield_unit);
        $parts = [];

        if ($yield <= 0) {
            $issues[] = 'Resep tidak punya jumlah hasil (yield).';
        } elseif ($quantity === null) {
            $issues[] = 'Satuan "'.$line['unit'].'" tidak sepadan dengan satuan hasil resep "'.$recipe->yield_unit.'".';
        } else {
            $context = ['recipe_id' => $recipe->id, 'qty' => (float) $line['qty'], 'unit' => $line['unit']];
            $parts = $this->parts($recipe, $quantity / $yield, [$recipe->id], $context, $issues, true);
        }

        $visible = collect($parts)->reject(fn (array $part) => $part['display'] === SpkComponentRule::DISPLAY_HIDDEN);

        return [
            'label' => $line['label'],
            'recipe_id' => $recipe->id,
            'title' => 'siapkan komponen '.$line['label'],
            'qty' => $line['qty'],
            'unit' => $line['unit'],
            'note' => $line['note'],
            'components' => $visible->values()->all(),
            'hidden' => collect($parts)->where('display', SpkComponentRule::DISPLAY_HIDDEN)->values()->all(),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * Pecah satu resep menjadi daftar komponen datar sesuai urutan kemunculan.
     *
     * Di tingkat menu, bahan tanpa bagian menjadi kelompok tanpa judul. Di dalam
     * sub-menu, bahan tanpa bagian dikembalikan sebagai baris milik sub-menu itu
     * (kunci 'rows' pada entri berjenis loose) supaya tampil di bawah judulnya.
     *
     * @param  array<int, int>  $path  Rantai resep, untuk memutus resep yang berputar.
     * @param  array{recipe_id: int, qty: float, unit: ?string}  $context
     * @param  array<int, string>  $issues
     * @return list<array<string, mixed>>
     */
    protected function parts(Recipe $recipe, float $multiplier, array $path, array $context, array &$issues, bool $isMenuLevel): array
    {
        $parts = [];
        $loosePosition = null;
        $looseRows = [];

        foreach ($this->itemsOf($recipe)->groupBy(fn (RecipeItem $item) => trim((string) $item->section)) as $section => $items) {
            $section = (string) $section;

            if ($section === '') {
                foreach ($items as $item) {
                    if ($this->isRef($item)) {
                        array_push($parts, ...$this->refComponent($item, null, $multiplier, $path, $context, $issues));
                    } elseif (! $this->isPackaging($item)) {
                        $loosePosition ??= count($parts);
                        $looseRows[] = $this->row($item, $multiplier);
                    }
                }

                continue;
            }

            // Bagian berisi satu sub-menu saja: judulnya memakai nama sub-menu itu.
            if ($items->count() === 1 && $this->isRef($items->first())) {
                array_push($parts, ...$this->refComponent($items->first(), $section, $multiplier, $path, $context, $issues));

                continue;
            }

            array_push($parts, ...$this->sectionComponent($section, $items, $multiplier, $path, $context, $issues));
        }

        if ($looseRows !== []) {
            $loose = $isMenuLevel ? $this->looseComponent($looseRows, $context) : ['type' => 'loose', 'display' => SpkComponentRule::DISPLAY_DETAIL, 'rows' => $looseRows];
            array_splice($parts, $loosePosition ?? 0, 0, [$loose]);
        }

        return $parts;
    }

    /** @param  list<array{name: string, qty: float, unit: ?string}>  $rows */
    protected function looseComponent(array $rows, array $context): array
    {
        $rule = $this->resolve($context['recipe_id'], [self::KEY_LOOSE]);
        $hidden = $this->hiddenNames($rule);

        return $this->component([
            'key' => self::KEY_LOOSE,
            'type' => 'loose',
            'name' => null,
            'default_name' => 'Bahan tanpa bagian',
            'display' => SpkComponentRule::DISPLAY_DETAIL,
            'rule' => $rule,
            'recipe_id' => $context['recipe_id'],
            'qty' => null,
            'unit' => null,
            'base_qty' => null,
            'show_qty' => false,
            'custom' => false,
            'note' => null,
            'rows' => $this->visibleRows($rows, $hidden, 1.0),
            'choices' => $this->choices($rows, [], $hidden),
        ]);
    }

    /**
     * @param  Collection<int, RecipeItem>  $items
     * @return list<array<string, mixed>>
     */
    protected function sectionComponent(string $section, Collection $items, float $multiplier, array $path, array $context, array &$issues): array
    {
        $key = $this->norm($section);
        $rule = $this->resolve($context['recipe_id'], [$key]);
        $display = $rule?->display ?? SpkComponentRule::DISPLAY_DETAIL;
        $override = $this->overrides[$context['recipe_id'].'|'.$key] ?? null;

        $rows = [];
        $refs = [];

        foreach ($items as $item) {
            if ($this->isRef($item)) {
                $refs[] = $item;
            } elseif (! $this->isPackaging($item)) {
                $rows[] = $this->row($item, $multiplier);
            }
        }

        // Bagian yang seluruhnya kemasan tidak punya isi untuk dapur.
        if ($rows === [] && $refs === []) {
            $display = SpkComponentRule::DISPLAY_HIDDEN;
        }

        [$headerQty, $headerUnit] = $this->header($rule, $rows, (float) $context['qty'], $context['unit'], $section, $issues);
        $customQty = $override?->qty === null ? null : (float) $override->qty;
        $factor = $customQty !== null && $headerQty > 0 ? $customQty / $headerQty : 1.0;
        $hidden = $this->hiddenNames($rule);

        $nested = [];
        if ($display === SpkComponentRule::DISPLAY_DETAIL) {
            foreach ($refs as $item) {
                if (! $this->isNamedIn($item, $hidden)) {
                    array_push($nested, ...$this->refComponent($item, null, $multiplier * $factor, $path, $context, $issues));
                }
            }
        }

        $component = $this->component([
            'key' => $key,
            'type' => 'section',
            'name' => $rule?->label ?: $section,
            'default_name' => $section,
            'display' => $display,
            'rule' => $rule,
            'recipe_id' => $context['recipe_id'],
            'qty' => $customQty ?? $headerQty,
            'unit' => $headerUnit,
            'base_qty' => $headerQty,
            'show_qty' => $display === SpkComponentRule::DISPLAY_SINGLE || $customQty !== null,
            'custom' => $customQty !== null,
            'note' => $override?->note,
            'rows' => $display === SpkComponentRule::DISPLAY_DETAIL ? $this->visibleRows($rows, $hidden, $factor) : [],
            'choices' => $this->choices($rows, $refs, $hidden),
        ]);

        return [$component, ...$nested];
    }

    /** @return list<array<string, mixed>> */
    protected function refComponent(RecipeItem $item, ?string $section, float $multiplier, array $path, array $context, array &$issues): array
    {
        /** @var Recipe $sub */
        $sub = $item->refRecipe;
        $key = $this->norm($sub->name);
        $rule = $this->resolve($context['recipe_id'], array_filter([$key, $this->norm($item->raw_name), $section ? $this->norm($section) : null]));
        $display = $rule?->display ?? SpkComponentRule::DISPLAY_DETAIL;
        $override = $this->overrides[$context['recipe_id'].'|'.$key] ?? null;
        $baseQty = (float) $item->qty * $multiplier;

        // Masalah urai hanya dilaporkan bila isinya memang akan ditampilkan.
        $isDetail = $display === SpkComponentRule::DISPLAY_DETAIL;
        $subYield = (float) $sub->yield_qty;
        $inYieldUnit = $this->converter->convert($baseQty, $item->unit, $sub->yield_unit);
        $canExpand = true;

        if (in_array($sub->id, $path, true)) {
            $canExpand = false;

            if ($isDetail) {
                $issues[] = 'Resep berputar di "'.$sub->name.'"; tidak diurai.';
            }
        } elseif ($subYield <= 0 || $inYieldUnit === null) {
            $canExpand = false;

            if ($isDetail) {
                $issues[] = 'Sub-menu "'.$sub->name.'" belum bisa diurai: satuan "'.$item->unit.'" tidak sepadan dengan hasil "'.$sub->yield_unit.'".';
            }
        }

        $baseChildren = [];
        if ($canExpand) {
            $childIssues = [];
            $baseChildren = $this->parts($sub, $inYieldUnit / $subYield, [...$path, $sub->id], $context, $childIssues, false);

            if ($isDetail) {
                array_push($issues, ...$childIssues);
            }
        }

        $baseRows = collect($baseChildren)->where('type', 'loose')->pluck('rows')->flatten(1)->values()->all();

        [$headerQty, $headerUnit] = $this->header($rule, $baseRows, $baseQty, $item->unit, $sub->name, $issues);
        $customQty = $override?->qty === null ? null : (float) $override->qty;
        $factor = $customQty !== null && $headerQty > 0 ? $customQty / $headerQty : 1.0;
        $hidden = $this->hiddenNames($rule);

        $rows = [];
        $nested = [];

        if ($isDetail && $canExpand) {
            // Dihitung ulang dengan faktor supaya sub-menu di dalamnya ikut terskala.
            $children = $factor === 1.0
                ? $baseChildren
                : $this->parts($sub, $inYieldUnit * $factor / $subYield, [...$path, $sub->id], $context, $childIssues, false);

            foreach ($children as $child) {
                if (($child['type'] ?? null) === 'loose') {
                    $rows = array_merge($rows, $child['rows']);
                } elseif (! in_array($child['key'], $hidden, true) && ! in_array($this->norm($child['default_name']), $hidden, true)) {
                    $nested[] = $child;
                }
            }

            $rows = $this->visibleRows($rows, $hidden, 1.0);
        }

        $choiceNames = collect($baseChildren)->reject(fn ($child) => ($child['type'] ?? null) === 'loose')->pluck('default_name')->all();

        $component = $this->component([
            'key' => $key,
            'type' => 'ref',
            'name' => $rule?->label ?: $item->raw_name,
            'default_name' => $item->raw_name,
            'display' => $display,
            'rule' => $rule,
            'recipe_id' => $context['recipe_id'],
            'qty' => $customQty ?? $headerQty,
            'unit' => $headerUnit,
            'base_qty' => $headerQty,
            'show_qty' => true,
            'custom' => $customQty !== null,
            'note' => $override?->note,
            'rows' => $rows,
            'choices' => $this->choices($baseRows, $choiceNames, $hidden),
        ]);

        return [$component, ...$nested];
    }

    /**
     * Jumlah di judul komponen: jumlah bahan utama bila dipilih, selain itu jumlah
     * komponen. Bahan utama yang sudah tidak ada di resep kembali ke jumlah komponen
     * dan dicatat, supaya tidak tampil angka kosong.
     *
     * @param  list<array{name: string, qty: float, unit: ?string}>  $rows
     * @param  array<int, string>  $issues
     * @return array{0: float, 1: ?string}
     */
    protected function header(?SpkComponentRule $rule, array $rows, float $qty, ?string $unit, string $name, array &$issues): array
    {
        if ($rule?->display === SpkComponentRule::DISPLAY_SINGLE && $rule->qty_basis === SpkComponentRule::BASIS_INGREDIENT) {
            foreach ($rows as $row) {
                if ($this->norm($row['name']) === $this->norm($rule->basis_item)) {
                    return [$row['qty'], $row['unit']];
                }
            }

            $issues[] = 'Bahan utama "'.$rule->basis_item.'" tidak ada di komponen "'.$name.'"; jumlahnya memakai jumlah komponen.';
        }

        return [$qty, $unit];
    }

    /** Lengkapi komponen dengan cakupan setelan untuk layar Atur Komponen. */
    protected function component(array $attributes): array
    {
        /** @var SpkComponentRule|null $rule */
        $rule = $attributes['rule'];
        unset($attributes['rule']);

        return array_merge($attributes, [
            'label' => $rule?->label,
            'basis_item' => $rule?->basis_item,
            'hidden_items' => $rule?->hidden_items ?? [],
            'scope' => $rule === null ? null : ($rule->recipe_id === null ? 'global' : 'menu'),
        ]);
    }

    /**
     * Setelan menu ini menang atas setelan semua menu, apa pun nama pencariannya.
     *
     * @param  array<int, string>  $keys
     */
    protected function resolve(int $recipeId, array $keys): ?SpkComponentRule
    {
        foreach ([$recipeId, null] as $scope) {
            foreach ($keys as $key) {
                if (isset($this->rules[$this->ruleKey($scope, $key)])) {
                    return $this->rules[$this->ruleKey($scope, $key)];
                }
            }
        }

        return null;
    }

    protected function ruleKey(?int $recipeId, string $key): string
    {
        return ($recipeId ?? 'all').'|'.$key;
    }

    /** @return array<int, string> */
    protected function hiddenNames(?SpkComponentRule $rule): array
    {
        return array_map(fn ($name) => $this->norm($name), $rule?->hidden_items ?? []);
    }

    /**
     * @param  list<array{name: string, qty: float, unit: ?string}>  $rows
     * @param  array<int, string>  $hidden
     * @return list<array{name: string, qty: float, unit: ?string}>
     */
    protected function visibleRows(array $rows, array $hidden, float $factor): array
    {
        return collect($rows)
            ->reject(fn (array $row) => in_array($this->norm($row['name']), $hidden, true))
            ->map(fn (array $row) => ['name' => $row['name'], 'qty' => round($row['qty'] * $factor, 2), 'unit' => $row['unit']])
            ->values()
            ->all();
    }

    /**
     * Pilihan bahan di layar Atur Komponen, termasuk yang sedang disembunyikan.
     *
     * @param  list<array{name: string, qty: float, unit: ?string}>  $rows
     * @param  iterable<int, RecipeItem|string>  $refs
     * @param  array<int, string>  $hidden
     * @return list<array{key: string, name: string, qty: ?float, unit: ?string, hidden: bool}>
     */
    protected function choices(array $rows, iterable $refs, array $hidden): array
    {
        $choices = [];

        foreach ($rows as $row) {
            $key = $this->norm($row['name']);
            $choices[$key] ??= ['key' => $key, 'name' => $row['name'], 'qty' => round($row['qty'], 2), 'unit' => $row['unit'], 'hidden' => in_array($key, $hidden, true)];
        }

        foreach ($refs as $ref) {
            $name = $ref instanceof RecipeItem ? $ref->raw_name : $ref;
            $key = $this->norm($name);
            $choices[$key] ??= ['key' => $key, 'name' => $name, 'qty' => null, 'unit' => null, 'hidden' => in_array($key, $hidden, true)];
        }

        return array_values($choices);
    }

    /** @return array{name: string, qty: float, unit: ?string} */
    protected function row(RecipeItem $item, float $multiplier): array
    {
        return ['name' => trim($item->raw_name), 'qty' => (float) $item->qty * $multiplier, 'unit' => $item->unit];
    }

    /** @param  array<int, string>  $names */
    protected function isNamedIn(RecipeItem $item, array $names): bool
    {
        return in_array($this->norm($item->raw_name), $names, true)
            || ($item->refRecipe && in_array($this->norm($item->refRecipe->name), $names, true));
    }

    protected function isRef(RecipeItem $item): bool
    {
        return $item->ref_recipe_id !== null && $item->refRecipe !== null;
    }

    protected function isPackaging(RecipeItem $item): bool
    {
        return $item->inventoryItem?->category === InventoryItem::CATEGORY_PACKAGING;
    }

    /** @return Collection<int, RecipeItem> */
    protected function itemsOf(Recipe $recipe): Collection
    {
        return $this->items[$recipe->id] ??= $recipe->items()->with(['inventoryItem', 'refRecipe'])->get();
    }

    protected function norm(?string $value): string
    {
        return Recipe::normalizeName($value);
    }
}
