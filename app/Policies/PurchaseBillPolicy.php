<?php

namespace App\Policies;

use App\Models\PurchaseBill;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Tagihan Pembelian: gudang (inventory.manage / requisition.check) membuat
 * dan mengajukan; accounting (purchase.pay) membaca dan memutuskan di
 * aplikasi Accounting. Tidak pernah dihapus: isinya pembelian yang sudah
 * masuk stok dan hutang yang sudah terbuku.
 */
class PurchaseBillPolicy extends ModulePolicy
{
    protected string $module = 'inventory';

    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view') || $user->can('purchase.pay');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.manage') || $user->can('requisition.check');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->create($user) && $record instanceof PurchaseBill && $record->isEditableByInventory();
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
