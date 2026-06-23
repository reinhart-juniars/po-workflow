<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'name'     => ['nullable', 'string'],
            'email'    => ['nullable', 'email'],
            'password' => ['required'],
        ]);

        $loginField = filled($credentials['email'] ?? null) ? 'email' : 'name';
        $loginValue = $credentials[$loginField] ?? null;

        if (! filled($loginValue)) {
            return back()
                ->withErrors(['email' => 'Email atau username wajib diisi.'])
                ->withInput();
        }

        if (! Auth::attempt([
            $loginField => $loginValue,
            'password' => $credentials['password'],
        ], $request->boolean('remember'))) {
            return back()
                ->withErrors([$loginField => 'Username atau password salah.'])
                ->onlyInput($loginField);
        }

        // ✅ Login sukses → regen session
        $request->session()->regenerate();

        // ✅ Block user nonaktif
        /** @var \App\Models\User $user */
        $user = $request->user();

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                $loginField => 'Akun ini sudah dinonaktifkan. Hubungi admin.',
            ])->onlyInput($loginField);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
