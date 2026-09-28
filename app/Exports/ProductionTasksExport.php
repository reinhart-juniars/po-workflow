<?php

namespace App\Exports;

use App\Models\ProductionOrder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** Lembar kerja (pembagian tugas pelaksana) satu SPK Produksi -- padanan export Produksi Master Menu. */
class ProductionTasksExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(
        protected ProductionOrder $order,
    ) {}

    public function headings(): array
    {
        return ['No.', 'PIC (Pelaksana)', 'Yang Dikerjakan (Menu)', 'Apa yang Dikerjakan', 'Objek', 'Jumlah', 'Selesai'];
    }

    public function collection(): Collection
    {
        return $this->order->tasks()
            ->with('recipe:id,name')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(fn ($task, int $i) => [
                $i + 1,
                $task->worker_name,
                $task->menu_label ?: $task->recipe?->name,
                $task->task,
                $task->object,
                $task->quantity_text,
                $task->is_done ? 'Ya' : '',
            ]);
    }
}
