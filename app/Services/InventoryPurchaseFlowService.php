<?php

namespace App\Services;

use App\Http\Controllers\Concerns\ResolvesCentralExpenseLocation;
use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\InventoryPurchase;
use App\Models\Payable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur uang di balik pembelian bahan baku.
 *
 * Sebuah pembelian tidak pernah berdiri sendiri: yang tunai harus punya
 * CashOut, yang kredit harus punya Payable, dan berpindah jenis pembayaran
 * berarti melepas pasangan yang lama. Kalau salah satu tertinggal, uang keluar
 * tercatat dua kali atau hilang sama sekali.
 *
 * Aturan itu dikumpulkan di sini supaya modul Blade dan panel Filament
 * memakai jalur yang sama persis, bukan dua salinan yang bisa menyimpang.
 */
class InventoryPurchaseFlowService
{
    use ResolvesCentralExpenseLocation;

    /**
     * Simpan perubahan pembelian beserta jurnal kas/hutangnya.
     *
     * @param  array<string, mixed>  $data  Data yang sudah lolos validasi form.
     */
    public function update(InventoryPurchase $purchase, array $data, ?int $actorId = null): InventoryPurchase
    {
        $data = $this->normalize($data);
        $this->assertFlowRequirements($data);

        DB::transaction(function () use ($purchase, $data, $actorId) {
            $purchase->update([
                'inventory_item_id' => $data['inventory_item_id'],
                'transaction_date' => $data['transaction_date'],
                'qty' => 1.0,
                'unit_cost' => (float) $data['total_cost'],
                'total_value' => (float) $data['total_cost'],
                'payment_type' => $data['payment_type'],
                'condition' => $data['condition'] ?? $purchase->condition ?? InventoryPurchase::CONDITION_GOOD,
                'condition_notes' => $data['condition_notes'] ?? $purchase->condition_notes,
                'condition_checked_at' => $data['condition_checked_at'] ?? $purchase->condition_checked_at,
                'condition_checked_by' => $data['condition_checked_by'] ?? $purchase->condition_checked_by,
                'supplier_name' => $data['supplier_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                // Tautan ke Form Kebutuhan: pembeliannya tetap lahir di sini,
                // form hanya menjadi alasannya. Kosong bila tidak disebut.
                'requisition_id' => array_key_exists('requisition_id', $data) ? ($data['requisition_id'] ?: null) : $purchase->requisition_id,
                'updated_by' => $actorId,
            ]);

            $this->syncFinancialFlow($purchase, $data, $actorId);
        });

        return $purchase->refresh();
    }

    /**
     * Buat pembelian baru beserta jurnal kas/hutangnya.
     *
     * Dipakai Form Kebutuhan saat Periksa: satu pembelian per baris bahan
     * dengan qty & harga beli yang sebenarnya, supaya nilai per bahan terbaca
     * di HPP dan nota tidak perlu diinput ulang oleh accounting.
     *
     * @param  array<string, mixed>  $data  inventory_item_id, transaction_date, qty, unit_cost,
     *                                      payment_type, expense_category_id/cash_account_id (tunai),
     *                                      supplier_name/due_date (kredit), condition, condition_notes,
     *                                      requisition_id, notes
     */
    public function create(array $data, ?int $actorId = null): InventoryPurchase
    {
        // Nilai dihitung dari harga presisi penuh (harga per gram bisa 4 desimal),
        // baru harga satuannya disimpan 2 desimal.
        $qty = round((float) $data['qty'], 2);
        $unitCost = round((float) $data['unit_cost'], 2);
        $data['total_cost'] = round((float) $data['qty'] * (float) $data['unit_cost'], 2);
        $data = $this->normalize($data);
        $this->assertFlowRequirements($data);

        return DB::transaction(function () use ($data, $qty, $unitCost, $actorId) {
            $purchase = InventoryPurchase::query()->create([
                'inventory_item_id' => $data['inventory_item_id'],
                'requisition_id' => $data['requisition_id'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'total_value' => $data['total_cost'],
                'payment_type' => $data['payment_type'],
                'condition' => $data['condition'] ?? InventoryPurchase::CONDITION_GOOD,
                'condition_notes' => $data['condition_notes'] ?? null,
                'condition_checked_at' => now(),
                'condition_checked_by' => $actorId,
                'supplier_name' => $data['supplier_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->syncFinancialFlow($purchase, $data, $actorId);

            return $purchase->refresh();
        });
    }

    /** Hapus pembelian beserta jurnal yang menempel padanya. */
    public function delete(InventoryPurchase $purchase): void
    {
        $purchase->load(['payable', 'cashOut']);

        DB::transaction(function () use ($purchase) {
            if ($purchase->payable && $purchase->payable->status !== 'unpaid') {
                throw ValidationException::withMessages([
                    'payment_type' => 'Pembelian stok kredit tidak bisa dihapus karena hutangnya sudah dibayar atau diproses.',
                ]);
            }

            $purchase->cashOut?->delete();
            $purchase->payable?->delete();
            $purchase->delete();
        });
    }

    /**
     * Kelengkapan yang tidak bisa diwakili aturan validasi per-field.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertFlowRequirements(array $data): void
    {
        $messages = [];

        if ($data['payment_type'] === 'payable' && blank($data['supplier_name'] ?? null)) {
            $messages['supplier_name'] = 'Supplier wajib diisi untuk pembelian kredit.';
        }

        if ($data['payment_type'] === 'cash') {
            if (blank($data['expense_category_id'] ?? null)) {
                $messages['expense_category_id'] = 'Kategori pengeluaran wajib dipilih untuk pembelian tunai.';
            }

            if (blank($data['cash_account_id'] ?? null)) {
                $messages['cash_account_id'] = 'Cash account wajib dipilih untuk pembelian tunai.';
            } elseif (! CashAccount::query()->whereKey($data['cash_account_id'])->exists()) {
                $messages['cash_account_id'] = 'Cash account tidak valid.';
            }

            if (filled($data['expense_category_id'] ?? null)) {
                $category = ExpenseCategory::query()->find($data['expense_category_id']);

                if (! $category || ! $category->isInventoryPurchase()) {
                    $messages['expense_category_id'] = 'Kategori untuk pembelian tunai harus bertipe Pembelian Stok.';
                }
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        // Jatuh tempo hanya bermakna untuk pembelian kredit.
        if (($data['payment_type'] ?? null) === 'cash') {
            $data['due_date'] = null;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function syncFinancialFlow(InventoryPurchase $purchase, array $data, ?int $actorId): void
    {
        if ($data['payment_type'] === 'cash') {
            $this->syncCashOut($purchase, $data, $actorId);
            $this->detachPayable($purchase);

            return;
        }

        $this->syncPayable($purchase, $data, $actorId);
        $this->detachCashOut($purchase);
    }

    /** @param array<string, mixed> $data */
    protected function syncCashOut(InventoryPurchase $purchase, array $data, ?int $actorId): void
    {
        $itemName = $purchase->item()->value('name') ?? 'Item inventory';

        $cashOut = CashOut::query()->updateOrCreate(
            ['id' => $purchase->cash_out_id],
            [
                'expense_category_id' => $data['expense_category_id'],
                'expense_location_id' => $this->centralExpenseLocationId(),
                'cash_account_id' => $data['cash_account_id'],
                'payable_id' => null,
                'amount' => (float) $data['total_cost'],
                'expense_date' => $data['transaction_date'],
                'description' => $data['notes'] ?? ('Pembelian stok '.$itemName),
                'is_adjustment' => false,
                'adjustment_note' => null,
                'adjusted_by' => null,
                'created_by' => $purchase->created_by ?? $actorId,
                'updated_by' => $actorId,
            ]
        );

        if ($purchase->cash_out_id !== $cashOut->id) {
            $purchase->update(['cash_out_id' => $cashOut->id]);
        }
    }

    /** @param array<string, mixed> $data */
    protected function syncPayable(InventoryPurchase $purchase, array $data, ?int $actorId): void
    {
        $itemName = $purchase->item()->value('name') ?? 'Item inventory';

        $payable = Payable::query()->updateOrCreate(
            ['id' => $purchase->payable_id],
            [
                'transaction_date' => $data['transaction_date'],
                'due_date' => $data['due_date'] ?? null,
                'supplier_name' => $data['supplier_name'],
                'description' => 'Pembelian stok '.$itemName,
                'amount' => (float) $data['total_cost'],
                'status' => 'unpaid',
                'paid_at' => null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $purchase->created_by ?? $actorId,
                'updated_by' => $actorId,
            ]
        );

        if ($purchase->payable_id !== $payable->id) {
            $purchase->update(['payable_id' => $payable->id]);
        }
    }

    protected function detachCashOut(InventoryPurchase $purchase): void
    {
        if (! $purchase->cash_out_id) {
            return;
        }

        CashOut::query()->whereKey($purchase->cash_out_id)->delete();
        $purchase->update(['cash_out_id' => null]);
    }

    protected function detachPayable(InventoryPurchase $purchase): void
    {
        if (! $purchase->payable_id) {
            return;
        }

        $payable = $purchase->payable()->first();

        if ($payable && $payable->status !== 'unpaid') {
            throw ValidationException::withMessages([
                'payment_type' => 'Pembelian ini sudah terkait hutang yang tidak bisa dilepas karena sudah dibayar atau diproses.',
            ]);
        }

        $payable?->delete();
        $purchase->update(['payable_id' => null]);
    }
}
