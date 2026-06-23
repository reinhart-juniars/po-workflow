<?php

namespace App\Policies;

use App\Models\Spk;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SpkPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Spk $spk): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Spk $spk): bool
    {
        return $spk->status !== 'completed';
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Spk $spk): bool
    {
        return $spk->status !== 'completed';
    }

    public function deleteAny(User $user): bool
    {
        // boleh menampilkan aksi bulk (Filament akan tetap cek per-record saat eksekusi)
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Spk $spk): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Spk $spk): bool
    {
        return false;
    }
}
