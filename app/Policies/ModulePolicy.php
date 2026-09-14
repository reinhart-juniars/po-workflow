<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Access\ModuleAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy generik untuk resource modul Inventory Terpadu: satu izin untuk
 * membaca, satu untuk mengelola. Nama izinnya didaftarkan di ModuleAccess;
 * turunan hanya menyebut modulnya.
 *
 * Superadmin lolos lewat Gate::before di AuthServiceProvider, jadi tidak
 * perlu dicek lagi di sini.
 */
abstract class ModulePolicy
{
    /** Awalan izin, mis. 'inventory' -> inventory.view / inventory.manage. */
    protected string $module;

    public function viewAny(User $user): bool
    {
        return $user->can($this->module.'.view');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can($this->module.'.manage');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->create($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->create($user);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->create($user);
    }

    public function reorder(User $user): bool
    {
        return $this->create($user);
    }

    /** Nama izin kelola, dipakai tes arsitektur untuk memastikan izinnya terdaftar. */
    public function permissions(): array
    {
        return [$this->module.'.view', $this->module.'.manage'];
    }

    public function isKnownModule(): bool
    {
        return in_array($this->module.'.view', ModuleAccess::names(), true);
    }
}
