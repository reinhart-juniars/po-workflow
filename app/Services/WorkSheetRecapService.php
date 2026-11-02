<?php

namespace App\Services;

use App\Models\DailyChore;
use App\Models\ProductionOrder;
use App\Models\ProductionTask;
use App\Support\Units\Unit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Rekap Lembar Kerja: semua pekerjaan dapur untuk satu tanggal kerja.
 *
 * Lembar Kerja disusun per SPK Produksi, padahal dapur bekerja per hari:
 * pada satu hari mereka mengerjakan H-1 dari SPK besok (beberapa slot) dan
 * persiapan H-2 / H-3 dari SPK lusa. Tanpa rekap, tim menyalin semuanya ke
 * Excel dengan tangan dan menjumlahkan sendiri ("daun bawang 120+90+50 gr").
 *
 * Baris dengan pelaksana, pekerjaan, dan objek yang sama digabung: menunya
 * disambung, jumlahnya ditulis "120+90 gr" bila satuannya sama. Angkanya
 * sengaja tidak dijumlahkan -- dapur perlu tahu porsi per menu saat membagi
 * hasil potongan, dan "120+90" tetap bisa dijumlah di kepala.
 */
class WorkSheetRecapService
{
    public const SORT_MENU = 'menu';

    public const SORT_WORKER = 'pelaksana';

    /**
     * @return array{
     *     date: CarbonInterface,
     *     rows: list<array{worker: ?string, task: ?string, object: ?string, quantity: ?string, menus: list<string>, orders: list<string>, days: list<int>, done: bool, count: int}>,
     *     orders: list<array{number: string, date: string, time: ?string, days: int}>,
     *     chores: Collection<int, DailyChore>
     * }
     */
    public function forDate(CarbonInterface $date, string $sort = self::SORT_MENU): array
    {
        $date = $date->copy()->startOfDay();

        // Tanggal kerja = tanggal SPK - days_before, dengan days_before 0..7:
        // SPK yang mungkin menyumbang pekerjaan ada di [tanggal, tanggal + 7].
        $orders = ProductionOrder::query()
            ->where('status', '!=', ProductionOrder::STATUS_CANCELLED)
            ->whereDate('production_date', '>=', $date->toDateString())
            ->whereDate('production_date', '<=', $date->copy()->addDays(ProductionTask::MAX_DAYS_BEFORE)->toDateString())
            ->with(['tasks.recipe:id,name'])
            ->orderBy('production_date')
            ->orderBy('production_time')
            ->orderBy('id')
            ->get();

        $tasks = $orders->flatMap(fn (ProductionOrder $order) => $order->tasks
            ->each(fn (ProductionTask $task) => $task->setRelation('productionOrder', $order))
            ->filter(fn (ProductionTask $task) => $task->workDate()?->isSameDay($date)));

        $rows = $this->merge($tasks);

        if ($sort === self::SORT_WORKER) {
            // Stabil: urutan menu tetap terjaga di dalam tiap pelaksana; yang
            // belum punya pelaksana di akhir supaya mudah dibagi.
            $rows = $rows->sortBy(fn (array $row, int $i) => [blank($row['worker']) ? 1 : 0, mb_strtolower((string) $row['worker']), $i]);
        }

        return [
            'date' => $date,
            'rows' => $rows->values()->all(),
            'orders' => $tasks->groupBy(fn (ProductionTask $task) => $task->production_order_id)
                ->map(fn (Collection $group) => [
                    'number' => $group->first()->productionOrder->number,
                    'date' => $group->first()->productionOrder->production_date->format('d/m/Y'),
                    'time' => $group->first()->productionOrder->production_time,
                    'days' => (int) $group->first()->days_before,
                ])
                ->values()
                ->all(),
            'chores' => DailyChore::query()->active()->get(),
        ];
    }

    /**
     * Gabungkan pekerjaan yang sama, urut kemunculan pertamanya.
     *
     * @param  Collection<int, ProductionTask>  $tasks
     * @return Collection<int, array<string, mixed>>
     */
    protected function merge(Collection $tasks): Collection
    {
        return $tasks
            ->groupBy(fn (ProductionTask $task) => implode('|', [
                $this->key($task->worker_name),
                $this->key($task->task),
                $this->key($task->object),
            ]))
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'worker' => $first->worker_name,
                    'task' => $first->task,
                    'object' => $first->object,
                    'quantity' => $this->joinQuantities($group->pluck('quantity_text')),
                    'menus' => $group->map(fn (ProductionTask $task) => $task->menu_label ?: $task->recipe?->name)
                        ->filter()->unique()->values()->all(),
                    'orders' => $group->map(fn (ProductionTask $task) => $task->productionOrder->number)
                        ->unique()->values()->all(),
                    'days' => $group->pluck('days_before')->map(fn ($d) => (int) $d)->unique()->sort()->values()->all(),
                    'done' => $group->every(fn (ProductionTask $task) => $task->is_done),
                    'count' => $group->count(),
                ];
            })
            ->values();
    }

    /**
     * "160 gr" + "90 gr" -> "160+90 gr"; satuan berbeda -> "2 kg + 300 gr".
     *
     * Satuan disamakan ejaannya dulu ("gr"/"gram"), tetapi tidak dikonversi:
     * isian ini teks bebas dapur, dan "4 kg + 300 gr" lebih jujur daripada
     * angka gabungan yang mungkin salah baca.
     *
     * @param  Collection<int, ?string>  $texts
     */
    public function joinQuantities(Collection $texts): ?string
    {
        $texts = $texts->map(fn ($text) => trim((string) $text))->filter()->values();

        if ($texts->isEmpty()) {
            return null;
        }

        $parsed = $texts->map(function (string $text) {
            // Angka (boleh sudah berbentuk "4+3" atau "1,5") lalu satuan.
            if (! preg_match('/^([\d.,]+(?:\s*\+\s*[\d.,]+)*)\s*(.*)$/u', $text, $m)) {
                return null;
            }

            return ['number' => preg_replace('/\s+/', '', $m[1]), 'unit' => Unit::canonical(trim($m[2])) ?? ''];
        });

        if ($parsed->contains(null) || $parsed->pluck('unit')->unique()->count() !== 1) {
            return $texts->implode(' + ');
        }

        return trim($parsed->pluck('number')->implode('+').' '.$parsed->first()['unit']);
    }

    protected function key(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
    }
}
