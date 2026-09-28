<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

class BaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Barat' => 'BRT',
            'Timur' => 'TMR',
            'Selatan' => 'SLT',
            'Utara' => 'UTR',
            'Pusat' => 'PST'] as $name => $code) {
            Area::firstOrCreate(['name' => $name], ['code' => $code]);
        }

        /**
         * SEED ROLES
         * Gunakan role final: superadmin, owner, admin, accounting, sales, marketing, production, delivery
         */
        foreach ([
            'superadmin',
            'owner',
            'admin',
            'accounting',
            'sales',
            'marketing',
            'production',
            'delivery'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // Pastikan ada satu superadmin
        $superadmin = User::whereRaw('LOWER(name) = ?', ['superadmin'])->first();

        if (! $superadmin) {
            $superadmin = User::create([
                'name' => 'Superadmin',
                'email' => 'superadmin@example.com', // boleh kamu ganti, ini cuma default
                'password' => Hash::make('password'), // WAJIB kamu ganti setelah seeding
            ]);
        }

        if (Schema::hasTable('roles') && method_exists($superadmin, 'assignRole')) {
            // pakai syncRoles supaya kalau dia punya role lain, kita tetapkan cuma superadmin
            $superadmin->syncRoles(['superadmin']);
        }
    }
}
