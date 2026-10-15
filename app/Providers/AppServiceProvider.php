<?php

namespace App\Providers;

use App\Models\Supplier;
use App\Services\AutoNumberService;
use App\Support\Settings\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
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
        // Antrean sinkron HPP produk dari resep hidup sepanjang satu request.
        $this->app->singleton(\App\Services\ProductRecipeCostSync::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Bagian C: satu build website mengunduh ~200 foto berturut-turut; batas
        // ini longgar untuk itu tapi tetap menghentikan tebak-tebakan token.
        RateLimiter::for('website-api', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        // Saran nama supplier untuk input teks bebas di aplikasi Blade.
        View::composer('partials.supplier-datalist', function ($view) {
            $view->with('supplierNames', Supplier::query()->active()->orderBy('name')->pluck('name'));
        });
    }
}
