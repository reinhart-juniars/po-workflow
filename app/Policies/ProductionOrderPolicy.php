<?php

namespace App\Policies;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProductionOrderPolicy extends ModulePolicy
{
    protected string $module = 'production';

    /** Gudang (plan.manage) boleh membuat SPK, tapi hanya sebagai Rencana Masak. */
    public function create(User $user): bool
    {
        return $user->can('production.manage') || $user->can('plan.manage');
    }

    public function update(User $user, Model $record): bool
    {
        return $record instanceof ProductionOrder && $record->canBeManagedBy($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    /** Menutup SPK memposting pemakaian ke ledger dan tidak bisa diulang. */
    public function complete(User $user, ProductionOrder $order): bool
    {
        return $user->can('production.complete');
    }
}
