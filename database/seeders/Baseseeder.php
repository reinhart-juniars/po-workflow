<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use App\Models\Area;

class BaseSeeder extends Seeder
{
    public function run(): void
    {
        // Areas
        foreach (['Barat' => 'BRT', 'Timur' => 'TMR', 'Selatan' => 'SLT', 'Utara' => 'UTR', 'Pusat' => 'PST'] as $name => $code) {
            Area::firstOrCreate(['name' => $name], ['code' => $code]);
        }

        // Roles (pakai guard_name 'web')
        foreach (['admin', 'production', 'delivery', 'owner'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }
}
