<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Completely bypass CSRF check for now (DEV MODE).
     */
    public function handle($request, Closure $next)
    {
        // langsung teruskan request tanpa cek token
        return $next($request);
    }

    /**
     * (boleh kosong / dibiarkan saja, nggak dipakai karena handle sudah override)
     */
    protected $except = [
        //
    ];
}
