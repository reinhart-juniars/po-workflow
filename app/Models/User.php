<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Peran yang boleh membuka panel admin Filament.
     *
     * Panel berisi master data, modul inventory, dan modul produksi (SPK
     * Produksi & Form Kebutuhan dipakai tim produksi); staf sales dan delivery
     * bekerja lewat aplikasi Blade masing-masing dan tidak membutuhkannya.
     * Apa yang boleh dibuka tiap peran di dalam panel diputuskan izin modul
     * (App\Support\Access\ModuleAccess), bukan daftar ini.
     */
    public const PANEL_ROLES = [
        'superadmin',
        'owner',
        'admin',
        'accounting',
        'production',
    ];

    public const MANAGEABLE_ROLES = [
        'owner',
        'admin',
        'accounting',
        'sales',
        'production',
        'delivery',
    ];

    private const DASHBOARD_ROUTE_BY_ROLE = [
        'superadmin' => 'superadmin.dashboard',
        'owner' => 'ownerapp.dashboard',
        'admin' => 'adminapp.dashboard',
        'accounting' => 'accountingapp.dashboard',
        'sales' => 'salesapp.dashboard',
        'production' => 'productionapp.dashboard',
        'delivery' => 'deliveryapp.dashboard',
    ];

    // kalau kamu pakai multi-guard & guard web:
    protected $guard_name = 'web';

    protected static function booted(): void
    {
        static::updating(function ($user) {
            // cegah menghapus role owner dari user owner, atau promote/demote owner via request biasa, dll.
            // dibiarkan kosong jika semua via Filament + Policy sudah cukup.
        });
    }

    public function isSuperadmin(): bool
    {
        return $this->hasRole('superadmin');
    }

    public static function manageableRoles(): array
    {
        return self::MANAGEABLE_ROLES;
    }

    public function preferredDashboardRouteName(): ?string
    {
        foreach (self::DASHBOARD_ROUTE_BY_ROLE as $role => $routeName) {
            if ($this->hasRole($role)) {
                return $routeName;
            }
        }

        return null;
    }

    public function accessibleAppKeys(): array
    {
        $keys = [];
        $staffApps = ['owner', 'admin', 'accounting', 'sales', 'production', 'delivery'];

        if ($this->hasRole('superadmin')) {
            $keys[] = 'superadmin';
        }

        if ($this->hasAnyRole(['superadmin', 'owner'])) {
            return array_merge($keys, $staffApps);
        }

        foreach ($staffApps as $appKey) {
            if ($this->hasRole($appKey)) {
                $keys[] = $appKey;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'force_password_change',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function mustChangePassword(): bool
    {
        return (bool) $this->force_password_change;
    }

    /**
     * Tanpa method ini Filament menolak setiap pengguna dengan 403 di semua
     * environment selain 'local', sehingga panel tidak terpakai di server --
     * dan sebaliknya, di 'local' siapa pun yang login bisa membuka seluruh
     * panel. Keputusannya karena itu dibuat eksplisit di sini.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasAnyRole(self::PANEL_ROLES);
    }
}
