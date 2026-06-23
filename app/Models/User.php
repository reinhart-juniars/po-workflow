<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

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
}
