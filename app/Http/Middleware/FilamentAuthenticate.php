<?php

namespace App\Http\Middleware;

use Filament\Http\Middleware\Authenticate;

/**
 * Panel inventory tidak punya halaman login sendiri: satu sistem, satu pintu
 * masuk (/login milik aplikasi Blade). Pengunjung yang belum login diarahkan
 * ke sana, bukan ke halaman login Filament.
 */
class FilamentAuthenticate extends Authenticate
{
    protected function redirectTo($request): ?string
    {
        return route('login');
    }
}
