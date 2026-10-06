<?php

namespace App\Services;

use App\Models\ProductionWorker;
use App\Models\Recipe;
use App\Models\RecipeTask;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Impor template Pekerjaan Menu dari Excel -- padanan import pekerjaan di
 * Master Menu Revamp, supaya berkas yang sudah dipakai klien bisa langsung
 * masuk. Format yang dikenali (baris judul di 8 baris pertama sheet mana pun):
 *
 *   export 3S ONE   : id_resep | Menu | Apa yang Dikerjakan | Objek | Jumlah | PIC
 *   export Master Menu: recipe_id | Menu | Apa yang Dikerjakan | Objek | Jumlah | PIC
 *   RINCIAN PRODUKSI : No | Menu | Proses | Bahan/Komponen | PIC
 *
 * Nama menu yang kosong mewarisi menu di baris atasnya. Resep dicocokkan
 * lewat id_resep (id 3S ONE), lalu recipe_id (id Master Menu = source_recipe_id),
 * lalu nama yang dinormalisasi. Yang tidak ketemu dilaporkan, tidak ditebak:
 * template yang tertempel ke menu yang salah lebih mahal daripada yang belum
 * masuk. Template resep yang cocok diganti seluruhnya oleh isi berkas.
 */
class RecipeTaskImporter
{
    public function __construct(
        protected MenuMatchSuggester $names,
    ) {}

    /**
     * @param  array<int|string, array<int, array<int, mixed>>>  $sheets  hasil Excel::toArray()
     * @return array{menus_matched: int, tasks_inserted: int, workers_created: int, unmatched: list<string>, duplicates: list<string>}
     */
    public function import(array $sheets): array
    {
        $groups = $this->parse($sheets);

        if ($groups === []) {
            throw new RuntimeException('Data tidak ditemukan. Pastikan ada kolom Menu dan kolom Apa yang Dikerjakan / Proses / Tugas.');
        }

        $recipes = Recipe::query()->get(['id', 'name', 'source_recipe_id']);
        $byId = $recipes->keyBy('id');
        $bySource = $recipes->whereNotNull('source_recipe_id')->keyBy('source_recipe_id');
        $byName = $recipes->groupBy(fn (Recipe $recipe) => $this->names->normalize($recipe->name));

        $matched = [];
        $unmatched = [];
        $duplicates = [];

        foreach ($groups as $group) {
            $recipe = match (true) {
                $group['id_resep'] !== null => $byId->get($group['id_resep']),
                $group['recipe_id'] !== null => $bySource->get($group['recipe_id']),
                default => null,
            };

            // Nama hanya dipakai bila cocok ke tepat satu resep.
            if (! $recipe) {
                $candidates = $byName->get($this->names->normalize($group['name']));
                $recipe = $candidates?->count() === 1 ? $candidates->first() : null;
            }

            if (! $recipe) {
                $unmatched[] = $group['name'];

                continue;
            }

            if (isset($matched[$recipe->id])) {
                // Dua nama di berkas jatuh ke resep yang sama: yang kedua dilewati
                // supaya tidak diam-diam menimpa yang pertama.
                $duplicates[] = $group['name'].' → '.$recipe->name;

                continue;
            }

            $matched[$recipe->id] = $group['tasks'];
        }

        $inserted = 0;
        $workersCreated = 0;

        DB::transaction(function () use ($matched, &$inserted, &$workersCreated) {
            foreach ($matched as $recipeId => $tasks) {
                RecipeTask::query()->where('recipe_id', $recipeId)->delete();

                foreach (array_values($tasks) as $i => $task) {
                    RecipeTask::query()->create(['recipe_id' => $recipeId, 'sort_order' => $i + 1] + $task);
                    $inserted++;

                    // PIC tunggal yang belum dikenal ikut menjadi pelaksana; gabungan
                    // ("tono / indra") atau "semua" bukan nama orang.
                    $pic = trim((string) $task['pic']);

                    if ($pic !== '' && ! preg_match('#[/+,&]#', $pic) && mb_strtolower($pic) !== 'semua') {
                        $worker = ProductionWorker::query()->firstOrCreate(['name' => $pic], ['is_active' => true]);
                        $workersCreated += $worker->wasRecentlyCreated ? 1 : 0;
                    }
                }
            }
        });

        return [
            'menus_matched' => count($matched),
            'tasks_inserted' => $inserted,
            'workers_created' => $workersCreated,
            'unmatched' => array_values(array_unique($unmatched)),
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @param  array<int|string, array<int, array<int, mixed>>>  $sheets
     * @return list<array{name: string, id_resep: ?int, recipe_id: ?int, tasks: list<array{task: string, object: ?string, quantity_text: ?string, pic: ?string}>}>
     */
    protected function parse(array $sheets): array
    {
        foreach ($sheets as $rows) {
            $header = $this->findHeader($rows);

            if ($header === null) {
                continue;
            }

            $groups = [];
            $current = null;

            foreach (array_slice($rows, $header['row'] + 1) as $row) {
                $cell = fn (?int $col) => $col === null ? '' : trim((string) ($row[$col] ?? ''));
                $menu = $cell($header['menu']);

                if ($menu !== '') {
                    $key = mb_strtolower(preg_replace('/\s+/u', ' ', $menu));
                    $groups[$key] ??= [
                        'name' => $menu,
                        'id_resep' => ctype_digit($cell($header['id_resep'])) ? (int) $cell($header['id_resep']) : null,
                        'recipe_id' => ctype_digit($cell($header['recipe_id'])) ? (int) $cell($header['recipe_id']) : null,
                        'tasks' => [],
                    ];
                    $current = $key;
                }

                $task = $cell($header['task']);

                if ($current === null || $task === '') {
                    continue;
                }

                $groups[$current]['tasks'][] = [
                    'task' => $task,
                    'object' => $cell($header['object']) ?: null,
                    'quantity_text' => $cell($header['quantity']) ?: null,
                    'pic' => $cell($header['pic']) ?: null,
                ];
            }

            return array_values(array_filter($groups, fn ($group) => $group['tasks'] !== []));
        }

        return [];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{row: int, menu: int, task: int, object: ?int, quantity: ?int, pic: ?int, id_resep: ?int, recipe_id: ?int}|null
     */
    protected function findHeader(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 8, true) as $index => $row) {
            $cells = array_map(fn ($value) => mb_strtolower(trim((string) $value)), $row);
            $find = function (callable $test) use ($cells): ?int {
                foreach ($cells as $col => $cell) {
                    if ($cell !== '' && $test($cell)) {
                        return $col;
                    }
                }

                return null;
            };

            $menu = $find(fn ($c) => in_array($c, ['menu', 'nama menu', 'yang dikerjakan', 'yang dikerjakan (menu)'], true));
            $task = $find(fn ($c) => in_array($c, ['proses', 'tugas', 'pekerjaan'], true) || (str_contains($c, 'dikerjakan') && ! str_starts_with($c, 'yang dikerjakan')));

            if ($menu === null || $task === null) {
                continue;
            }

            return [
                'row' => $index,
                'menu' => $menu,
                'task' => $task,
                'object' => $find(fn ($c) => $c === 'objek' || str_contains($c, 'bahan') || str_contains($c, 'komponen')),
                'quantity' => $find(fn ($c) => str_contains($c, 'jumlah') || str_contains($c, 'porsi')),
                'pic' => $find(fn ($c) => in_array($c, ['pic', 'pic (default)', 'nama', 'orang', 'petugas', 'pelaksana'], true)),
                'id_resep' => $find(fn ($c) => $c === 'id_resep'),
                'recipe_id' => $find(fn ($c) => $c === 'recipe_id'),
            ];
        }

        return null;
    }
}
