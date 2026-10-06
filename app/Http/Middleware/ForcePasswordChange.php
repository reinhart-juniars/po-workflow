<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        if (
            Auth::check() &&
            Auth::user()->force_password_change &&
            ! $request->routeIs('profile.edit') &&
            ! $request->routeIs('profile.password.update')
        ) {
            return redirect()
                ->route('profile.edit')
                ->with('warning', 'Silakan ganti password Anda terlebih dahulu.');
        }

        return $next($request);
    }
}
