<?php

namespace App\Policies;

use App\Models\Requisition;
use App\Models\User;

/**
 * Form Kebutuhan mengikuti SPK produksinya untuk lihat/kelola; tahap
 * persetujuannya punya izin tersendiri karena orangnya biasanya berbeda.
 */
class RequisitionPolicy extends ModulePolicy
{
    protected string $module = 'production';

    public function approve(User $user, Requisition $requisition): bool
    {
        return $user->can('requisition.approve');
    }

    public function check(User $user, Requisition $requisition): bool
    {
        return $user->can('requisition.check');
    }
}
