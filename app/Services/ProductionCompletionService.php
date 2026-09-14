<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\ProductionOrder;
use App\Models\RequisitionLine;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menutup SPK Produksi dan memposting pemakaian bahan ke ledger.
 *
 * Inilah titik yang menggantikan HPP residual: pemakaian tiap bahan dicatat
 * sebagai gerakan keluar per SPK, sebesar aktual yang dihitung dapur bila ada,
 * atau sebesar kebutuhan resep bila tidak. Bila sisa stok ikut dihitung fisik,
 * selisih antara saldo ledger dan sisa nyata diposting sebagai penyesuaian
 * -- terpisah dari pemakaian, supaya susut dan salah takar terlihat sebagai
 * angkanya sendiri, bukan tersembunyi di dalam HPP.
 */
class ProductionCompletionService
{
    public function __construct(
        protected InventoryLedgerService $ledger,
    ) {}

    /**
     * Catat pemakaian aktual dan/atau sisa stok sebuah baris.
     *
     * Hanya bisa selama form sudah diperiksa dan SPK belum ditutup: sebelum
     * diperiksa barangnya belum ada, setelah ditutup angkanya sudah diposting.
     */
    public function recordActuals(RequisitionLine $line, ?float $actualUsedQty, ?float $remainingQty): RequisitionLine
    {
        $requisition = $line->requisition;

        if (! $requisition->isChecked()) {
            throw new RuntimeException('Form '.$requisition->number.' belum diperiksa; pemakaian aktual belum bisa dicatat.');
        }

        if ($requisition->productionOrder->isCompleted()) {
            throw new RuntimeException('SPK Produksi sudah ditutup; pemakaiannya sudah diposting ke ledger.');
        }

        if ($actualUsedQty !== null && $actualUsedQty < 0) {
            throw new RuntimeException('Pemakaian aktual tidak boleh negatif.');
        }

        if ($remainingQty !== null && $remainingQty < 0) {
            throw new RuntimeException('Sisa stok tidak boleh negatif.');
        }

        $line->actual_used_qty = $actualUsedQty === null ? null : round($actualUsedQty, 4);
        $line->remaining_qty = $remainingQty === null ? null : round($remainingQty, 4);
        $line->save();

        return $line;
    }

    /**
     * Tutup SPK Produksi: posting pemakaian (dan penyesuaian) ke ledger.
     *
     * @return array{usage_value: float, adjustment_value: float, lines: int}
     */
    public function complete(ProductionOrder $order, ?int $userId = null): array
    {
        if ($order->isCompleted()) {
            throw new RuntimeException('SPK Produksi '.$order->number.' sudah ditutup; pemakaiannya tidak boleh diposting dua kali.');
        }

        if ($order->isCancelled()) {
            throw new RuntimeException('SPK Produksi '.$order->number.' sudah dibatalkan.');
        }

        $requisition = $order->requisition;

        if (! $requisition || ! $requisition->isChecked()) {
            throw new RuntimeException('Form kebutuhan SPK ini belum diperiksa. Pemakaian hanya bisa diposting setelah barangnya tercatat masuk.');
        }

        if (app(Settings::class)->bool('production.require_remaining_on_close')) {
            $belumDiisi = $requisition->lines()->whereNull('remaining_qty')->count();

            if ($belumDiisi > 0) {
                throw new RuntimeException("{$belumDiisi} bahan belum diisi Sisa Stok. Pengaturan mewajibkan sisa stok fisik dicatat sebelum SPK ditutup.");
            }
        }

        return DB::transaction(function () use ($order, $requisition, $userId) {
            $date = $order->production_date->toDateString();
            $usageValue = 0.0;
            $adjustmentValue = 0.0;
            $count = 0;

            foreach ($requisition->lines()->get() as $line) {
                $price = $line->unit_price === null ? null : (float) $line->unit_price;
                $usage = $line->usageQty();

                if ($usage > 0) {
                    $movement = $this->ledger->post(
                        $line->inventory_item_id,
                        InventoryMovement::TYPE_USAGE,
                        -$usage,
                        $line->unit,
                        $date,
                        $price,
                        [
                            'requisition_line_id' => $line->id,
                            'production_order_id' => $order->id,
                            'notes' => ($line->actual_used_qty === null ? 'Pemakaian resep ' : 'Pemakaian aktual ').$order->number,
                            'created_by' => $userId,
                        ],
                    );

                    $usageValue += (float) ($movement->total_value ?? 0);
                    $count++;
                }

                // Sisa hasil hitungan fisik menjadi saldo ledger yang baru;
                // selisihnya dicatat sebagai penyesuaian, bukan pemakaian.
                if ($line->remaining_qty !== null) {
                    $balance = $this->ledger->balance($line->inventory_item_id, $date);
                    $difference = round((float) $line->remaining_qty - $balance, 4);

                    if (abs($difference) >= 0.00005) {
                        $movement = $this->ledger->post(
                            $line->inventory_item_id,
                            InventoryMovement::TYPE_ADJUSTMENT,
                            $difference,
                            $line->unit,
                            $date,
                            $price,
                            [
                                'requisition_line_id' => $line->id,
                                'production_order_id' => $order->id,
                                'notes' => 'Penyesuaian ke sisa stok '.$order->number,
                                'created_by' => $userId,
                            ],
                        );

                        $adjustmentValue += (float) ($movement->total_value ?? 0);
                    }
                }
            }

            $order->update([
                'status' => ProductionOrder::STATUS_COMPLETED,
                'completed_at' => now(),
                'completed_by' => $userId,
                'updated_by' => $userId,
            ]);

            return [
                // Pemakaian bertanda negatif di ledger; dilaporkan sebagai angka positif.
                'usage_value' => round(-$usageValue, 2),
                'adjustment_value' => round($adjustmentValue, 2),
                'lines' => $count,
            ];
        });
    }
}
