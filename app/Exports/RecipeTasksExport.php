<?php

namespace App\Exports;

use App\Models\RecipeTask;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Template Pekerjaan Menu, satu baris per tugas -- format sama dengan export
 * Master Menu (id_resep menggantikan recipe_id), bisa diimpor ulang.
 */
class RecipeTasksExport implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'Pekerjaan Menu';
    }

    public function headings(): array
    {
        return ['id_resep', 'Menu', 'Apa yang Dikerjakan', 'Objek', 'Jumlah', 'PIC'];
    }

    public function collection(): Collection
    {
        return RecipeTask::query()
            ->join('recipes', 'recipes.id', '=', 'recipe_tasks.recipe_id')
            ->orderBy('recipes.name')
            ->orderBy('recipe_tasks.sort_order')
            ->orderBy('recipe_tasks.id')
            ->get(['recipe_tasks.*', 'recipes.name as recipe_name'])
            ->map(fn (RecipeTask $task) => [
                $task->recipe_id, $task->recipe_name, $task->task, $task->object, $task->quantity_text, $task->pic,
            ]);
    }
}
