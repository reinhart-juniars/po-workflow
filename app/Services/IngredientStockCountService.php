<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Opname Bahan: hitung fisik per bahan (kuantitas) di luar penutupan SPK.
 *
 * Stock Opname lama mencatat nilai rupiah per kategori dan tidak tahu bahan
 * apa yang kurang. Di sini hasil hitung dibandingkan dengan saldo kartu stok
 * pada tanggal opname, dan selisihnya diposting sebagai penyesuaian:
 *
 *   hitung < saldo -> Barang Hilang   (penyesuaian negatif)
 *   hitung > saldo -> Barang Temuan   (penyesuaian positif)
 *
 * Bahan yang belum pernah tercatat di kartu stok tidak punya saldo untuk
 * dibandingkan, jadi hitungan pertamanya menjadi Saldo Awal -- bukan temuan.
 */
class IngredientStockCountService
{
    public function __construct(
        protected InventoryLedgerService $ledger,
    ) {}

    /**
     * @param  array<int, float|int|string|null>  $counts  id bahan => hasil hitung fisik (kosong = tidak dihitung)
     * @return array{hilang: int, temuan: int, saldo_awal: int, sama: int, hilang_value: float, temuan_value: float}
     */
    public function record(CarbonInterface $date, array $counts, ?string $notes = null, ?int $userId = null): array
    {
        if ($date->isAfter(now()->endOfDay())) {
            throw new InvalidArgumentException('Tanggal opname tidak boleh di masa depan.');
        }

        $counts = collect($counts)
            ->reject(fn ($value) => $value === null || $value === '')
            ->map(fn ($value) => round((float) $value, 4));

        if ($counts->isEmpty()) {
            throw new InvalidArgumentException('Belum ada hasil hitung yang diisi.');
        }

        if ($counts->contains(fn (float $value) => $value < 0)) {
            throw new InvalidArgumentException('Hasil hitung tidak boleh negatif.');
        }

        $items = InventoryItem::query()
            ->ingredients()
            ->whereIn('id', $counts->keys())
            ->get(['id', 'name', 'unit', 'unit_price'])
            ->keyBy('id');

        $unknown = $counts->keys()->diff($items->keys());

        if ($unknown->isNotEmpty()) {
            throw new InvalidArgumentException('Bahan tidak dikenal: '.$unknown->implode(', '));
        }

        $label = 'Opname bahan '.$date->format('d/m/Y').(filled($notes) ? ' — '.trim((string) $notes) : '');
        $dateString = $date->toDateString();
        $withoutHistory = collect($this->ledger->withoutHistory($counts->keys()->all()))->flip();

        return DB::transaction(function () use ($counts, $items, $withoutHistory, $dateString, $label, $userId) {
            $summary = ['hilang' => 0, 'temuan' => 0, 'saldo_awal' => 0, 'sama' => 0, 'hilang_value' => 0.0, 'temuan_value' => 0.0];

            foreach ($counts as $itemId => $counted) {
                $item = $items->get($itemId);
                $price = $item->unit_price === null ? null : (float) $item->unit_price;
                $attributes = ['notes' => $label, 'created_by' => $userId];

                if ($withoutHistory->has($itemId)) {
                    if ($counted > 0) {
                        $this->ledger->post($itemId, InventoryMovement::TYPE_OPENING, $counted, $item->unit, $dateString, $price, $attributes);
                        $summary['saldo_awal']++;
                    }

                    continue;
                }

                $difference = round($counted - $this->ledger->balance($itemId, $dateString), 4);

                if (abs($difference) < 0.00005) {
                    $summary['sama']++;

                    continue;
                }

                $movement = $this->ledger->post($itemId, InventoryMovement::TYPE_ADJUSTMENT, $difference, $item->unit, $dateString, $price, $attributes);

                if ($difference < 0) {
                    $summary['hilang']++;
                    $summary['hilang_value'] += -(float) ($movement->total_value ?? 0);
                } else {
                    $summary['temuan']++;
                    $summary['temuan_value'] += (float) ($movement->total_value ?? 0);
                }
            }

            $summary['hilang_value'] = round($summary['hilang_value'], 2);
            $summary['temuan_value'] = round($summary['temuan_value'], 2);

            return $summary;
        });
    }
}
