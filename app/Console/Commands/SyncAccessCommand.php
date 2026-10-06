<?php

namespace App\Console\Commands;

use App\Support\Access\ModuleAccess;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Daftarkan izin modul Inventory Terpadu dan bagikan ke peran sesuai matriks
 * bawaan. Dijalankan setiap deploy (idempoten); `--reset` mengembalikan
 * setiap peran persis ke matriks bawaan, mencabut yang pernah diubah manual.
 */
class SyncAccessCommand extends Command
{
    protected $signature = 'access:sync {--reset : Kembalikan izin setiap peran ke matriks bawaan}';

    protected $description = 'Sinkronkan izin modul (spatie permission) ke peran sesuai matriks ModuleAccess';

    public function handle(): int
    {
        $result = ModuleAccess::sync(reset: (bool) $this->option('reset'));

        $this->info("{$result['permissions']} izin, {$result['roles']} peran tersinkron".($this->option('reset') ? ' (reset ke bawaan).' : '.'));

        $rows = Role::query()->with('permissions')->orderBy('name')->get()
            ->map(fn (Role $role) => [
                $role->name,
                in_array($role->name, ModuleAccess::SUPER_ROLES, true)
                    ? 'semua (superadmin)'
                    : ($role->permissions->pluck('name')->sort()->implode(', ') ?: '-'),
            ]);

        $this->table(['Peran', 'Izin modul'], $rows->all());

        return self::SUCCESS;
    }
}
