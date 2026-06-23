<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\PurchaseOrder::class => \App\Policies\PurchaseOrderPolicy::class,
        \App\Models\Spk::class           => \App\Policies\SpkPolicy::class,
        \App\Models\DeliveryOrder::class => \App\Policies\DeliveryOrderPolicy::class,
        \App\Models\User::class          => \App\Policies\UserPolicy::class,
        \App\Models\Product::class       => \App\Policies\ProductPolicy::class,
        // tambahkan policy lain di sini nanti, misal:
        // \App\Models\Spk::class => \App\Policies\SpkPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
        
        Gate::define('assignRoles', fn($user) => 
        $user->hasRole('owner'));

        Gate::define('manage-po', fn($user) =>
        $user->hasAnyRole(['owner','admin','administrator']));

        // contoh Gate tambahan kalau kamu perlu nanti
        // Gate::define('isAdmin', fn ($user) => $user->role === 'admin');
        
    }
}
