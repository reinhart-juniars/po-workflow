<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
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
    public function view(User $user, User $target): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $target): bool
    {
        // owner boleh edit semua, selain itu hanya boleh edit dirinya sendiri
        return $user->hasRole('owner') || $user->id === $target->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $target): bool
    {
        // larang delete diri sendiri & larang delete owner
        if ($user->id === $target->id) return false;
        if ($target->hasRole('owner')) return false;
        return $user->hasRole('owner');
    }

    // ability khusus untuk assign roles
    public function assignRoles(User $user, User $target): bool
    {
        if (!$user->hasRole('owner')) return false;         // hanya owner
        if ($target->id === $user->id) return false;        // jangan assign diri sendiri
        if ($target->hasRole('owner')) return false;        // jangan ubah owner lain
        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
