<?php

namespace App\Providers;

use App\Services\AutoNumberService;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('autonumber', function () {
            return new AutoNumberService;
        });

        // Pengaturan modul dibaca di banyak tempat dalam satu request; satu
        // instance supaya cache-nya juga satu.
        $this->app->singleton(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
    }
}
