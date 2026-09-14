<?php

namespace App\Policies;

use App\Models\User;

/**
 * Ledger stok hanya dibaca; barisnya ditulis oleh alur produksi, bukan dari
 * resource ini, jadi tidak ada izin kelola sama sekali.
 */
class InventoryMovementPolicy extends ModulePolicy
{
    protected string $module = 'ledger';

    public function create(User $user): bool
    {
        return false;
    }
}
