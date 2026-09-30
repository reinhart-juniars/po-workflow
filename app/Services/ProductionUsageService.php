<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pemakaian bahan menurut resep x produksi, dibandingkan dengan residual opname.
 *
 * Selama masa uji paralel (DoD Phase 3) keduanya dihitung berdampingan untuk
 * bucket yang sama dan periode yang sama:
 *
 *   residual = Bahan Baku Lama + Pembelian - Sisa Stok   (InventoryUsageService)
 *   resep    = jumlah nilai gerakan `usage` di ledger, per bahan di bawah bucket
 *
 * Penyesuaian (selisih ke sisa hitungan fisik) dilaporkan terpisah: itulah
 * bagian residual yang selama ini menyamar sebagai pemakaian.
 */
class ProductionUsageService
{
    public function __construct(
        protected InventoryUsageService $residual,
        protected InventoryLedgerService $ledger,
    ) {}

    /**
     * Pemakaian resep untuk sebuah bucket dalam satu periode.
     *
     * @return array{usage: float, adjustment: float, movements: int}
     */
    public function recipeUsageForBucket(int $bucketId, CarbonInterface $from, CarbonInterface $to): array
    {
        [$fromDate, $toDate] = [$from->toDateString(), $to->toDateString()];

        $movements = InventoryMovement::query()
            ->whereHas('item', fn ($query) => $query->where('parent_id', $bucketId))
            ->whereDate('moved_at', '>=', $fromDate)->whereDate('moved_at', '<=', $toDate)
            ->count();

        return [
            // Pemakaian bertanda negatif di ledger; dilaporkan positif.
            'usage' => round(-$this->ledger->valueForBucket($bucketId, InventoryMovement::TYPE_USAGE, $fromDate, $toDate), 2),
            // Penyesuaian negatif = susut; dilaporkan sebagai angka positif
            // pengurang stok supaya sejajar dengan pemakaian.
            'adjustment' => round(-$this->ledger->valueForBucket($bucketId, InventoryMovement::TYPE_ADJUSTMENT, $fromDate, $toDate), 2),
            'movements' => $movements,
        ];
    }

    /**
     * Perbandingan residual vs resep untuk satu bucket.
     *
     * @return array<string, mixed>
     */
    public function compareBucket(InventoryItem $bucket, CarbonInterface $from, CarbonInterface $to): array
    {
        $residual = $this->residual->calculateForItem($bucket->id, $from, $to);
        $recipe = $this->recipeUsageForBucket($bucket->id, $from, $to);

        $residualUsage = round((float) $residual['usage'], 2);
        $recipeTotal = round($recipe['usage'] + $recipe['adjustment'], 2);
        $variance = round($residualUsage - $recipeTotal, 2);

        return [
            'bucket_id' => $bucket->id,
            'bucket' => $bucket->name,
            'residual' => $residualUsage,
            'residual_source' => $residual['ending_source'],
            'recipe_usage' => $recipe['usage'],
            'recipe_adjustment' => $recipe['adjustment'],
            'recipe_total' => $recipeTotal,
            'variance' => $variance,
            'variance_pct' => $residualUsage != 0.0 ? round($variance / $residualUsage, 4) : null,
            'movements' => $recipe['movements'],
        ];
    }

    /**
     * Perbandingan untuk seluruh bucket stok.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function compareAll(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return InventoryItem::query()
            ->buckets()
            ->whereIn('category', InventoryItem::stockCategories())
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryItem $bucket) => $this->compareBucket($bucket, $from, $to));
    }
}
