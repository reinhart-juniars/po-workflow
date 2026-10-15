<?php

use App\Http\Controllers\Api\WebsiteMenuController;
use App\Http\Middleware\EnsureWebsiteToken;
use Illuminate\Support\Facades\Route;

// Bagian C: data menu untuk skrip build www.w3scatering.com (token, tanpa sesi).
// Throttle dulu, baru token: tebakan token yang salah ikut terhitung.
Route::middleware(['throttle:website-api', EnsureWebsiteToken::class])
    ->prefix('website')
    ->name('api.website.')
    ->group(function () {
        Route::get('/menu', [WebsiteMenuController::class, 'index'])->name('menu');
        Route::get('/menu/{product}/photo', [WebsiteMenuController::class, 'photo'])
            ->whereNumber('product')
            ->name('menu.photo');
    });
