<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        $dashboardRoute = $user->preferredDashboardRouteName();

        if ($dashboardRoute) {
            return redirect()->route($dashboardRoute);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->withErrors([
                'name' => 'Akun kamu belum punya role yang valid. Hubungi admin.',
            ]);
    }
}
