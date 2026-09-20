<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryPurchase;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Support\Notify;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Form Kebutuhan, Stok & Pembelian Barang per SPK Produksi.
 *
 * Alur persetujuannya bertingkat dan tidak bisa dilompati:
 *
 *   draft     (Dibuat/Diisi) -> angka kebutuhan dari resep; dapur mengisi
 *                               Stok Awal, Beli dihitung otomatis
 *   submitted (Diajukan)     -> produksi mengajukan; supervisor gudang
 *                               menyetujui, atau menolak kembali ke draft
 *   approved  (Disetujui)    -> isian dikunci; menunggu barang dibeli
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
        protected InventoryPurchaseFlowService $purchases,
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
     * Produksi mengajukan form ke supervisor gudang. Seluruh Stok Awal harus
     * sudah terisi -- form yang belum diisi bukan form yang bisa diajukan.
     */
    public function submit(Requisition $requisition, ?int $userId = null): Requisition
    {
        if (! $requisition->isDraft()) {
            throw new RuntimeException('Form '.$requisition->number.' sudah '.mb_strtolower($requisition->statusLabel()).'.');
        }

        $blank = $requisition->lines()->whereNull('opening_stock_qty')->count();

        if ($blank > 0) {
            throw new RuntimeException("Masih ada {$blank} baris yang Stok Awal-nya belum diisi. Form baru bisa diajukan setelah seluruh stok dihitung.");
        }

        if ($requisition->lines()->count() === 0) {
            throw new RuntimeException('Form kosong: SPK Produksi ini belum punya baris menu yang bisa dihitung kebutuhannya.');
        }

        $requisition->update([
            'status' => Requisition::STATUS_SUBMITTED,
            'submitted_by' => $userId,
            'submitted_at' => now(),
        ]);

        $this->notify(
            'requisition.approve',
            $requisition->number.' menunggu persetujuan',
            'Diajukan oleh '.($requisition->submittedBy?->name ?? 'produksi').' untuk '.$requisition->productionOrder->number.' ('.$requisition->lines()->count().' bahan).',
            $requisition,
            $userId,
        );

        return $requisition;
    }

    /**
     * Supervisor gudang menyetujui form yang diajukan. Stok Awal & Beli
     * terkunci sejak itu; gudang mulai belanja / menerima barang.
     */
    public function approve(Requisition $requisition, ?int $userId = null): Requisition
    {
        if (! $requisition->isSubmitted()) {
            throw new RuntimeException($requisition->isDraft()
                ? 'Form '.$requisition->number.' belum diajukan produksi; yang disetujui adalah form yang sudah diajukan.'
                : 'Form '.$requisition->number.' sudah '.mb_strtolower($requisition->statusLabel()).'.');
        }

        $requisition->update([
            'status' => Requisition::STATUS_APPROVED,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        $this->notify(
            'requisition.check',
            $requisition->number.' disetujui, siap dibelanjakan',
            'Disetujui oleh '.($requisition->approvedBy?->name ?? 'supervisor').'. Catat penerimaan barang, lalu Periksa.',
            $requisition,
            $userId,
        );

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
    /**
     * Catat jumlah yang benar-benar diterima (tahap Disetujui, sebelum
     * Periksa). Barang "Tidak Baik" tidak boleh ikut masuk stok, jadi yang
     * diterima boleh lebih kecil dari Beli -- tetapi tidak lebih besar, karena
     * kelebihan kiriman dicatat sebagai pembelian tersendiri.
     */
    public function recordReceivedQty(RequisitionLine $line, ?float $receivedQty): RequisitionLine
    {
        return $this->recordReceipt($line, $receivedQty);
    }

    /**
     * Catat penerimaan satu baris: jumlah diterima, alasan & perlakuan barang
     * yang ditolak, dan harga beli aktual dari nota. Ditolak selalu = Beli -
     * Diterima; yang disimpan hanya alasan dan perlakuannya.
     *
     * Kelengkapan (alasan wajib bila ada yang ditolak) baru dituntut saat
     * Periksa, supaya isian bisa disimpan bertahap.
     */
    public function recordReceipt(
        RequisitionLine $line,
        ?float $receivedQty,
        ?string $rejectedReason = null,
        ?string $rejectedTreatment = null,
        ?float $purchasePrice = null,
    ): RequisitionLine {
        $requisition = $line->requisition;

        if (! $requisition->isApproved()) {
            throw new RuntimeException('Form '.$requisition->number.' belum disetujui atau sudah diperiksa; penerimaan hanya dicatat di antaranya.');
        }

        if ($receivedQty !== null && $receivedQty < 0) {
            throw new RuntimeException('Jumlah diterima tidak boleh negatif.');
        }

        if ($receivedQty !== null && $receivedQty > (float) $line->purchase_qty + 0.00005) {
            throw new RuntimeException('Diterima untuk '.$line->name.' melebihi Beli ('.(float) $line->purchase_qty.' '.$line->unit.'). Kelebihan kiriman dicatat sebagai pembelian terpisah.');
        }

        if ($rejectedTreatment !== null && ! array_key_exists($rejectedTreatment, RequisitionLine::rejectTreatmentOptions())) {
            throw new RuntimeException('Perlakuan barang ditolak tidak dikenal: '.$rejectedTreatment);
        }

        if ($purchasePrice !== null && $purchasePrice < 0) {
            throw new RuntimeException('Harga beli tidak boleh negatif.');
        }

        $line->received_qty = $receivedQty === null ? null : round($receivedQty, 4);
        $line->rejected_qty = $line->rejectedQty();
        $line->rejected_reason = $line->rejected_qty > 0 ? ($rejectedReason ?: null) : null;
        $line->rejected_treatment = $line->rejected_qty > 0
            ? ($rejectedTreatment ?? app(Settings::class)->get('requisition.reject_default_treatment'))
            : null;
        $line->purchase_price = $purchasePrice === null ? null : round($purchasePrice, 4);
        $line->save();

        return $line;
    }

    /**
     * Cara pembayaran belanja untuk form ini (tunai: kategori + akun kas;
     * kredit: supplier + jatuh tempo). Dipakai saat Periksa untuk membuat
     * pembelian bahan baku beserta kas keluar / hutangnya.
     *
     * @param  array{payment_type?: ?string, expense_category_id?: mixed, cash_account_id?: mixed, supplier_name?: ?string, due_date?: mixed}  $data
     */
    public function recordPaymentHeader(Requisition $requisition, array $data): Requisition
    {
        if (! $requisition->isApproved()) {
            throw new RuntimeException('Form '.$requisition->number.' belum disetujui atau sudah diperiksa; cara pembayaran hanya dicatat di antaranya.');
        }

        $type = $data['payment_type'] ?? null;

        if ($type !== null && ! array_key_exists($type, Requisition::paymentTypeOptions())) {
            throw new RuntimeException('Jenis pembayaran tidak dikenal: '.$type);
        }

        $requisition->update([
            'payment_type' => $type,
            'expense_category_id' => $type === 'cash' ? ($data['expense_category_id'] ?: $this->defaultPurchaseCategoryId()) : null,
            'cash_account_id' => $type === 'cash' ? ($data['cash_account_id'] ?: null) : null,
            'supplier_name' => filled($data['supplier_name'] ?? null) ? trim((string) $data['supplier_name']) : null,
            'due_date' => $type === 'payable' ? ($data['due_date'] ?: null) : null,
        ]);

        return $requisition;
    }

    /** Kategori Pembelian Stok bawaan bila hanya ada satu yang aktif. */
    public function defaultPurchaseCategoryId(): ?int
    {
        $ids = ExpenseCategory::query()
            ->where('expense_mode', ExpenseCategory::MODE_INVENTORY_PURCHASE)
            ->where('is_active', true)
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /**
     * Yang menghalangi Periksa. Kosong berarti siap.
     *
     * @return list<string>
     */
    public function checkBlockers(Requisition $requisition): array
    {
        $blockers = [];
        $lines = $requisition->lines()->get();
        $adaPembelian = false;

        foreach ($lines as $line) {
            if ($line->rejectedQty() > 0 && blank($line->rejected_reason)) {
                $blockers[] = $line->name.': '.$this->qty($line->rejectedQty()).' '.$line->unit.' ditolak tanpa alasan.';
            }

            if ($line->receivedQty() > 0 && ($line->purchasePrice() === null || $line->purchasePrice() <= 0)) {
                $blockers[] = $line->name.': harga beli belum diisi.';
            }

            if ($line->receivedQty() > 0 || $line->damagedValue() > 0) {
                $adaPembelian = true;
            }
        }

        if ($adaPembelian) {
            if ($requisition->payment_type === null) {
                $blockers[] = 'Pilih cara pembayaran belanja (tunai / kredit).';
            } elseif ($requisition->payment_type === 'cash') {
                if (! $requisition->cash_account_id) {
                    $blockers[] = 'Pilih akun kas untuk pembelian tunai.';
                }

                if (! $requisition->expense_category_id) {
                    $blockers[] = 'Pilih kategori pengeluaran (Pembelian Stok) untuk pembelian tunai.';
                }
            } elseif (blank($requisition->supplier_name)) {
                $blockers[] = 'Isi nama supplier untuk pembelian kredit.';
            }
        }

        return $blockers;
    }

    /**
     * Supervisor gudang menolak form yang diajukan: kembali ke draft supaya
     * produksi memperbaiki lalu mengajukan ulang. Alasan wajib dan tercatat
     * (siapa, kapan, kenapa) sampai form diajukan lagi.
     */
    public function reject(Requisition $requisition, string $reason, ?int $userId = null): Requisition
    {
        if (! $requisition->isSubmitted()) {
            throw new RuntimeException('Form '.$requisition->number.' tidak sedang menunggu persetujuan.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Alasan penolakan wajib diisi supaya produksi tahu apa yang harus diperbaiki.');
        }

        $requisition->update([
            'status' => Requisition::STATUS_DRAFT,
            'submitted_by' => null,
            'submitted_at' => null,
            'rejected_by' => $userId,
            'rejected_at' => now(),
            'rejection_reason' => trim($reason),
        ]);

        $this->notify(
            'production.manage',
            $requisition->number.' ditolak supervisor gudang',
            trim($reason).' — perbaiki lalu ajukan lagi.',
            $requisition,
            $userId,
            'danger',
        );

        return $requisition;
    }

    /**
     * Kirim notifikasi lonceng ke semua pengguna aktif yang punya izin
     * tertentu (lewat perannya), kecuali pelaku sendiri.
     */
    protected function notify(string $permission, string $title, string $body, Requisition $requisition, ?int $actorId, string $status = 'info'): void
    {
        Notify::permission(
            $permission,
            $title,
            $body,
            \App\Filament\Resources\ProductionOrderResource::getUrl('kebutuhan', ['record' => $requisition->production_order_id]),
            $status,
            $actorId,
            'Buka form',
        );
    }

    public function check(Requisition $requisition, ?int $userId = null): Requisition
    {
        if (! $requisition->isApproved()) {
            throw new RuntimeException('Form '.$requisition->number.' belum disetujui, atau sudah diperiksa.');
        }

        $blockers = $this->checkBlockers($requisition);

        if ($blockers !== []) {
            throw new RuntimeException('Belum bisa diperiksa: '.implode(' ', $blockers));
        }

        return DB::transaction(function () use ($requisition, $userId) {
            $order = $requisition->productionOrder;
            $date = $order->production_date->toDateString();
            $lines = $requisition->lines()->get();
            $fresh = $this->ledger->withoutHistory($lines->pluck('inventory_item_id')->all());
            $updateMasterPrice = app(Settings::class)->bool('requisition.update_master_price');

            foreach ($lines as $line) {
                $price = $line->unit_price === null ? null : (float) $line->unit_price;
                // Pembelian dinilai dengan harga beli aktual (bila dicatat), bukan
                // harga master saat form disusun.
                $buyPrice = $line->purchasePrice();

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

                // Yang masuk stok adalah yang diterima layak pakai, bukan yang
                // dipesan: barang datang rusak sudah dikurangi di received_qty.
                if ($line->receivedQty() > 0) {
                    $this->ledger->post(
                        $line->inventory_item_id,
                        InventoryMovement::TYPE_PURCHASE,
                        $line->receivedQty(),
                        $line->unit,
                        $date,
                        $buyPrice,
                        [
                            'requisition_line_id' => $line->id,
                            'production_order_id' => $order->id,
                            'notes' => 'Pembelian untuk '.$requisition->number,
                            'created_by' => $userId,
                        ],
                    );
                }

                $this->recordPurchases($requisition, $line, $date, $userId);

                if ($updateMasterPrice) {
                    $this->syncMasterPrice($requisition, $line);
                }
            }

            $requisition->update([
                'status' => Requisition::STATUS_CHECKED,
                'checked_by' => $userId,
                'checked_at' => now(),
            ]);

            $this->notify(
                'production.complete',
                $requisition->number.' diperiksa, barang sudah masuk',
                'Bahan untuk '.$order->number.' tercatat di kartu stok. Isi pemakaian aktual lalu Tutup SPK setelah produksi.',
                $requisition,
                $userId,
                'success',
            );

            return $requisition;
        });
    }

    /**
     * Pembelian bahan baku dari satu baris: yang diterima (kondisi Baik) dan,
     * bila barang ditolak tetap dibayar, satu lagi berkondisi Tidak Baik yang
     * nilainya masuk Kerugian Barang Rusak. Kas keluar / hutangnya ikut
     * terbentuk lewat InventoryPurchaseFlowService, jalur yang sama dengan
     * modul Pengeluaran.
     */
    protected function recordPurchases(Requisition $requisition, RequisitionLine $line, string $date, ?int $userId): void
    {
        $price = $line->purchasePrice();

        if ($price === null || $price <= 0) {
            return;
        }

        $base = [
            'inventory_item_id' => $line->inventory_item_id,
            'requisition_id' => $requisition->id,
            'transaction_date' => $date,
            'unit_cost' => $price,
            'payment_type' => $requisition->payment_type,
            'expense_category_id' => $requisition->expense_category_id,
            'cash_account_id' => $requisition->cash_account_id,
            'supplier_name' => $requisition->supplier_name,
            'due_date' => $requisition->due_date?->toDateString(),
        ];

        if ($line->receivedQty() > 0) {
            $purchase = $this->purchases->create($base + [
                'qty' => $line->receivedQty(),
                'condition' => InventoryPurchase::CONDITION_GOOD,
                'notes' => 'Pembelian '.$line->name.' untuk '.$requisition->number,
            ], $userId);

            $line->inventory_purchase_id = $purchase->id;
        }

        if ($line->damagedValue() > 0) {
            $damaged = $this->purchases->create($base + [
                'qty' => $line->rejectedQty(),
                'condition' => InventoryPurchase::CONDITION_DAMAGED,
                'condition_notes' => $line->rejected_reason,
                'notes' => 'Barang ditolak (dibayar) '.$line->name.' untuk '.$requisition->number,
            ], $userId);

            $line->damaged_purchase_id = $damaged->id;
        }

        $line->save();
    }

    /**
     * Harga beli dari nota menjadi harga master bahan (SSOT harga), tercatat
     * di histori harga dengan sumber nomor form -- supaya HPP resep berikutnya
     * memakai harga terbaru dan lonjakannya bisa dijelaskan.
     */
    protected function syncMasterPrice(Requisition $requisition, RequisitionLine $line): void
    {
        $price = $line->purchasePrice();

        if ($line->purchase_price === null || $price === null || $price <= 0 || $line->receivedQty() <= 0) {
            return;
        }

        $item = InventoryItem::query()->find($line->inventory_item_id);

        if (! $item || $item->unit !== $line->unit || abs((float) ($item->effectiveUnitPrice() ?? 0) - $price) < 0.00005) {
            return;
        }

        $previous = InventoryItem::$priceChangeSource;
        InventoryItem::$priceChangeSource = 'Form '.$requisition->number;

        try {
            $item->update(['unit_price' => $price]);
        } finally {
            InventoryItem::$priceChangeSource = $previous;
        }
    }

    protected function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ',');
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
