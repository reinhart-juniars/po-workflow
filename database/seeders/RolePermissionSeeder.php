<?php

namespace Database\Seeders;

use App\Support\Access\ModuleAccess;
use Illuminate\Database\Seeder;

/** Izin modul Inventory Terpadu; matriksnya ada di ModuleAccess. */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        ModuleAccess::sync();
    }
}
