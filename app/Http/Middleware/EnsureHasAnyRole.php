<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureHasAnyRole
{
    /**
     * Pakai: 'ensure.role:admin|owner|administrator'
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (! $user) {
            abort(401); // belum login
        }

        // Kalau trait belum nempel atau method tidak ada, tolak
        if (! method_exists($user, 'hasAnyRole')) {
            abort(403, 'Role system not available');
        }

        // roles datang sebagai 1 argumen string dipisah '|', atau beberapa argumen
        if (count($roles) === 1 && str_contains($roles[0], '|')) {
            $roles = explode('|', $roles[0]);
        }

        if (! $user->hasAnyRole($roles)) {
            abort(403, 'Insufficient role');
        }

        return $next($request);
    }
}
