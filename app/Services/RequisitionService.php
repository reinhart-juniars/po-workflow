<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryPurchase;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Form Kebutuhan, Stok & Pembelian Barang per SPK Produksi.
 *
 * Alur persetujuannya bertingkat dan tidak bisa dilompati:
 *
 *   draft    (Dibuat/Diisi)  -> angka kebutuhan dari resep; dapur mengisi
 *                               Stok Awal, Beli dihitung otomatis
 *   approved (Disetujui)     -> isian dikunci; menunggu barang dibeli
 *   checked  (Diperiksa)     -> barang sudah dibeli & diperiksa; saldo awal
 *                               dan pembelian diposting ke ledger
 *
 * Posting ke ledger baru terjadi pada langkah terakhir, karena sebelum itu
 * angkanya masih rencana. Saldo awal sebuah bahan diambil dari Stok Awal pada
 * form pertama yang menyebutnya -- itulah keputusan yang dikunci: tidak ada
 * sesi opname kuantitas terpisah.
 */
class RequisitionService
{
    public function __construct(
        protected ProductionOrderService $orders,
        protected InventoryLedgerService $ledger,
    ) {}

    /**
     * Susun form untuk sebuah SPK Produksi, atau segarkan angka kebutuhannya.
     *
     * Isian manusia (Stok Awal, Beli yang disunting, catatan) dipertahankan;
     * hanya kebutuhan, nama, satuan, dan harga yang disegarkan. Baris yang
     * bahannya sudah tidak dibutuhkan dihapus bila belum diisi apa pun.
     *
     * @return array{requisition: Requisition, issues: array<int, string>}
     */
    public function build(ProductionOrder $order, ?int $userId = null): array
    {
        return DB::transaction(function () use ($order, $userId) {
            $requisition = Requisition::query()->firstOrCreate(
                ['production_order_id' => $order->id],
                ['status' => Requisition::STATUS_DRAFT, 'prepared_by' => $userId, 'prepared_at' => now()],
            );

            if (! $requisition->isDraft()) {
                throw new RuntimeException('Form '.$requisition->number.' sudah '.mb_strtolower($requisition->statusLabel()).'; angkanya tidak bisa disusun ulang.');
            }

            $requirements = $this->orders->requirements($order);
            $existing = $requisition->lines()->get()->keyBy('inventory_item_id');
            $seen = [];
            $sort = 0;

            foreach ($requirements['rows'] as $row) {
                $attributes = [
                    'sort_order' => ++$sort,
                    'name' => $row['name'],
                    'unit' => $row['unit'],
                    'required_qty' => $row['qty'],
                    'unit_price' => $row['unit_price'],
                ];

                $line = $existing->get($row['inventory_item_id']);

                if ($line) {
                    $line->fill($attributes);

                    // Beli mengikuti rumus lagi hanya bila belum pernah disunting
                    // tangan, yaitu masih sama dengan saran dari angka lama.
                    if ($this->wasSuggested($line)) {
                        $line->purchase_qty = $line->suggestedPurchaseQty();
                    }

                    $line->save();
                } else {
                    $line = $requisition->lines()->create($attributes + [
                        'inventory_item_id' => $row['inventory_item_id'],
                    ]);
                    $line->purchase_qty = $line->suggestedPurchaseQty();
                    $line->save();
                }

                $seen[] = $row['inventory_item_id'];
            }

            $requisition->lines()
                ->whereNotIn('inventory_item_id', $seen)
                ->whereNull('opening_stock_qty')
                ->whereNull('actual_used_qty')
                ->whereNull('remaining_qty')
                ->delete();

            return [
                'requisition' => $requisition->fresh(['lines']),
                'issues' => $requirements['issues'],
            ];
        });
    }

    /**
     * Isi Stok Awal sebuah baris; Beli dihitung ulang dari rumus.
     *
     * Beli yang pernah disunting tangan ikut dihitung ulang: begitu Stok Awal
     * berubah, angka lama tidak lagi punya dasar.
     */
    public function fillOpeningStock(RequisitionLine $line, ?float $openingStockQty): RequisitionLine
    {
        $this->assertDraft($line->requisition);

        $line->opening_stock_qty = $openingStockQty;
        $line->purchase_qty = $line->suggestedPurchaseQty();
        $line->save();

        return $line;
    }

    /** Sunting Beli secara manual, mis. dibulatkan ke kemasan. */
    public function overridePurchaseQty(RequisitionLine $line, float $purchaseQty): RequisitionLine
    {
        $this->assertDraft($line->requisition);

        if ($purchaseQty < 0) {
            throw new RuntimeException('Jumlah beli tidak boleh negatif.');
        }

        $line->purchase_qty = round($purchaseQty, 4);
        $line->save();

        return $line;
    }

    /**
     * Setujui form. Seluruh Stok Awal harus sudah terisi -- form yang belum
     * diisi bukan form yang bisa disetujui.
     */
    public function approve(Requisition $requisition, ?int $userId = null): Requisition
    {
        if (! $requisition->isDraft()) {
            throw new RuntimeException('Form '.$requisition->number.' sudah '.mb_strtolower($requisition->statusLabel()).'.');
        }

        $blank = $requisition->lines()->whereNull('opening_stock_qty')->count();

        if ($blank > 0) {
            throw new RuntimeException("Masih ada {$blank} baris yang Stok Awal-nya belum diisi. Form baru bisa disetujui setelah seluruh stok dihitung.");
        }

        if ($requisition->lines()->count() === 0) {
            throw new RuntimeException('Form kosong: SPK Produksi ini belum punya baris menu yang bisa dihitung kebutuhannya.');
        }

        $requisition->update([
            'status' => Requisition::STATUS_APPROVED,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        return $requisition;
    }

    /**
     * Tandai barang sudah dibeli dan diperiksa, lalu posting ke ledger.
     *
     * Dua gerakan diposting per baris, keduanya dalam satuan harga bahan:
     * 1. opening  -- hanya untuk bahan yang belum pernah ada di ledger; Stok
     *                Awal di form inilah saldo pembukanya.
     * 2. purchase -- sebesar Beli, bila lebih dari nol.
     *
     * Dilakukan dalam satu transaksi, dan dilarang diulang: form yang sudah
     * diperiksa tidak bisa diperiksa lagi, sehingga ledger tidak terisi dua kali.
     */
    public function check(Requisition $requisition, ?int $userId = null): Requisition
    {
        if (! $requisition->isApproved()) {
            throw new RuntimeException('Form '.$requisition->number.' belum disetujui, atau sudah diperiksa.');
        }

        return DB::transaction(function () use ($requisition, $userId) {
            $order = $requisition->productionOrder;
            $date = $order->production_date->toDateString();
            $lines = $requisition->lines()->get();
            $fresh = $this->ledger->withoutHistory($lines->pluck('inventory_item_id')->all());

            foreach ($lines as $line) {
                $price = $line->unit_price === null ? null : (float) $line->unit_price;

                if (in_array($line->inventory_item_id, $fresh, true) && $line->opening_stock_qty !== null) {
                    $this->ledger->post(
                        $line->inventory_item_id,
                        InventoryMovement::TYPE_OPENING,
                        (float) $line->opening_stock_qty,
                        $line->unit,
                        $date,
                        $price,
                        [
                            'requisition_line_id' => $line->id,
                            'production_order_id' => $order->id,
                            'notes' => 'Stok Awal pada '.$requisition->number,
                            'created_by' => $userId,
                        ],
                    );
                }

                if ((float) $line->purchase_qty > 0) {
                    $this->ledger->post(
                        $line->inventory_item_id,
                        InventoryMovement::TYPE_PURCHASE,
                        (float) $line->purchase_qty,
                        $line->unit,
                        $date,
                        $price,
                        [
                            'requisition_line_id' => $line->id,
                            'production_order_id' => $order->id,
                            'notes' => 'Pembelian untuk '.$requisition->number,
                            'created_by' => $userId,
                        ],
                    );
                }
            }

            $requisition->update([
                'status' => Requisition::STATUS_CHECKED,
                'checked_by' => $userId,
                'checked_at' => now(),
            ]);

            return $requisition;
        });
    }

    /**
     * Tautkan pencatatan pembelian bahan baku ke form ini.
     *
     * Pembeliannya sendiri tetap lahir dari modul Pengeluaran; di sini hanya
     * dicatat bahwa pembelian itu dilakukan untuk form ini.
     */
    public function linkPurchase(Requisition $requisition, InventoryPurchase $purchase): void
    {
        $purchase->update(['requisition_id' => $requisition->id]);
    }

    protected function assertDraft(Requisition $requisition): void
    {
        if (! $requisition->isDraft()) {
            throw new RuntimeException('Form '.$requisition->number.' sudah '.mb_strtolower($requisition->statusLabel()).'; isiannya dikunci.');
        }
    }

    /** Beli tersimpan masih sama dengan saran rumus, artinya belum disunting tangan. */
    protected function wasSuggested(RequisitionLine $line): bool
    {
        $original = $line->getOriginal('purchase_qty');
        $originalRequired = (float) $line->getOriginal('required_qty');
        $opening = $line->opening_stock_qty === null ? 0.0 : (float) $line->opening_stock_qty;

        return $original === null || abs((float) $original - max($originalRequired - $opening, 0)) < 0.00005;
    }
}
