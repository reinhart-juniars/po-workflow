<?php

namespace App\Policies;

use App\Models\User;

/**
 * Konversi satuan per bahan dipakai dua pihak: tim menu (supaya HPP resep
 * terhitung) dan gudang (satuan beli di Form Kebutuhan). Salah satu izin
 * modulnya cukup.
 */
class InventoryUnitConversionPolicy extends ModulePolicy
{
    protected string $module = 'inventory';

    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view') || $user->can('recipe.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.manage') || $user->can('recipe.manage');
    }
}
