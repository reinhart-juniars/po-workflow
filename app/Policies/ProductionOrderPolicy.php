<?php

namespace App\Policies;

use App\Models\ProductionOrder;
use App\Models\User;

class ProductionOrderPolicy extends ModulePolicy
{
    protected string $module = 'production';

    /** Menutup SPK memposting pemakaian ke ledger dan tidak bisa diulang. */
    public function complete(User $user, ProductionOrder $order): bool
    {
        return $user->can('production.complete');
    }
}
