<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan hasil baca Excel rencana masak menjadi Rencana Masak per tanggal.
 *
 * Aman diimpor ulang (klien sering mengirim revisi): baris yang lahir dari
 * impor sebelumnya pada Rencana Masak tanggal itu disusun ulang, baris yang
 * ditambah manual tidak disentuh. Rencana yang sudah ditutup/dibatalkan tidak
 * diubah -- tanggal itu dilewati dengan pesan.
 *
 * Baris menu:
 *  - produk berresep  -> baris menu (dihitung bahannya, bisa didistribusikan);
 *  - produk tanpa resep / belum dicocokkan -> baris manual bertanda, supaya
 *    dapur tetap melihat seluruh rencana.
 * Pesanan khusus (di bawah total Excel) tidak diimpor; itu PO customer.
 */
class ReadyStockPlanImporter
{
    public function __construct(private readonly ReadyStockPlanMatcher $matcher) {}

    /**
     * Pratinjau: hasil baca + pencocokan, tanpa menulis apa pun.
     *
     * @param  list<array<string, mixed>>  $days  hasil ReadyStockPlanReader::read()
     * @return list<array<string, mixed>>
     */
    public function preview(array $days): array
    {
        return array_map(function (array $day) {
            $day['items'] = array_map(function (array $item) use ($day) {
                $match = $this->matcher->match($item['name'], $item['price']);

                return $item + [
                    'key' => self::key($day['date'], $item['row'], $item['name']),
                    'product_id' => $match['product_id'],
                    'via' => $match['via'],
                    'candidates' => $match['candidates'],
                ];
            }, $day['items']);

            $plan = $this->existingPlan(Carbon::parse($day['date']));
            $day['plan'] = $plan ? ['id' => $plan->id, 'number' => $plan->number, 'editable' => $plan->isEditable(), 'status' => $plan->statusLabel()] : null;
            $day['total_ok'] = $day['sheet_total'] === null
                || abs($day['sheet_total'] - array_sum(array_column($day['items'], 'qty'))) < 0.001;

            return $day;
        }, $days);
    }

    /**
     * @param  list<array<string, mixed>>  $preview  hasil preview()
     * @param  array<string, int|string|null>  $choices  key baris => product_id pilihan manual
     * @return list<array{date: string, number: ?string, action: string, lines: int, menu_lines: int, message: ?string}>
     */
    public function apply(array $preview, array $choices, User $user): array
    {
        return DB::transaction(function () use ($preview, $choices, $user) {
            $products = Product::query()
                ->whereIn('id', collect($preview)->flatMap(fn ($day) => collect($day['items'])->map(fn ($item) => $choices[$item['key']] ?? $item['product_id']))->filter()->unique())
                ->with('recipe')
                ->get()
                ->keyBy('id');

            $result = [];

            foreach ($preview as $day) {
                $date = Carbon::parse($day['date']);
                $plan = $this->existingPlan($date);

                if ($plan && ! $plan->isEditable()) {
                    $result[] = ['date' => $day['date'], 'number' => $plan->number, 'action' => 'dilewati', 'lines' => 0, 'menu_lines' => 0,
                        'message' => 'Rencana Masak '.$plan->number.' sudah '.mb_strtolower($plan->statusLabel()).'; tidak diubah.'];

                    continue;
                }

                $action = $plan ? 'diperbarui' : 'dibuat';
                $plan ??= ProductionOrder::query()->create([
                    'title' => 'Rencana Masak '.ucfirst($day['day']).' '.$date->format('d/m/Y'),
                    'is_ready_stock' => true,
                    'production_date' => $date->toDateString(),
                    'status' => ProductionOrder::STATUS_DRAFT,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                $plan->lines()->where('source', ProductionOrderLine::SOURCE_IMPORT)->delete();
                $sort = (int) $plan->lines()->max('sort_order');
                $menuLines = 0;

                foreach ($day['items'] as $item) {
                    $chosen = $choices[$item['key']] ?? null;
                    $productId = $chosen ?: $item['product_id'];
                    $product = $productId ? $products->get((int) $productId) : null;

                    if ($chosen && $product) {
                        $this->matcher->remember($item['name'], $item['price'], $product->id, $user->id);
                    }

                    $recipe = $product?->recipe?->is_active ? $product->recipe : null;
                    $menuLines += $recipe ? 1 : 0;

                    $plan->lines()->create([
                        'kind' => $recipe ? ProductionOrderLine::KIND_MENU : ProductionOrderLine::KIND_MANUAL,
                        'source' => ProductionOrderLine::SOURCE_IMPORT,
                        'recipe_id' => $recipe?->id,
                        'label' => $product?->name ?? $item['name'],
                        'qty' => $item['qty'],
                        'unit' => $recipe?->yield_unit ?: 'porsi',
                        'remark' => match (true) {
                            $product === null => 'Belum dicocokkan ke Master Menu (Excel: '.$item['name'].')',
                            $recipe === null => 'Menu belum punya resep; belum bisa didistribusikan dari stok siap',
                            default => null,
                        },
                        'sort_order' => ++$sort,
                    ]);
                }

                $plan->update(['updated_by' => $user->id]);

                AuditLog::create([
                    'user_id' => $user->id,
                    'entity' => 'production_order',
                    'entity_id' => $plan->id,
                    'action' => 'ready_stock_imported',
                    'message' => sprintf('Rencana Masak %s %s dari Excel: %d menu (%d berresep).', $plan->number, $action, count($day['items']), $menuLines),
                ]);

                $result[] = ['date' => $day['date'], 'number' => $plan->number, 'action' => $action, 'lines' => count($day['items']), 'menu_lines' => $menuLines, 'message' => null];
            }

            return $result;
        });
    }

    /** Kunci baris pratinjau: stabil antara pratinjau dan simpan. */
    public static function key(string $date, int $row, string $name): string
    {
        return $date.'-'.$row.'-'.substr(md5($name), 0, 6);
    }

    /**
     * Rencana Masak tanggal itu yang dipakai impor: yang sudah berisi baris
     * impor, kalau tidak ada yang masih bisa diubah, kalau tidak ada yang
     * terakhir dibuat (supaya yang sudah ditutup dilaporkan, bukan diduplikasi).
     */
    private function existingPlan(Carbon $date): ?ProductionOrder
    {
        $plans = ProductionOrder::query()->readyStock()
            ->whereDate('production_date', $date->toDateString())
            ->where('status', '!=', ProductionOrder::STATUS_CANCELLED)
            ->withCount(['lines as imported_lines' => fn ($query) => $query->where('source', ProductionOrderLine::SOURCE_IMPORT)])
            ->orderByDesc('id')
            ->get();

        return $plans->firstWhere('imported_lines', '>', 0)
            ?? $plans->first(fn (ProductionOrder $plan) => $plan->isEditable())
            ?? $plans->first();
    }
}
