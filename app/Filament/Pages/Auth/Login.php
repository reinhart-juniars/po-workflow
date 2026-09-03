<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Login panel admin yang mengikuti aturan aplikasi Blade.
 *
 * Login bawaan Filament hanya menerima email, sedangkan sebagian besar pengguna
 * di sistem ini tidak punya email dan selama ini masuk memakai nama (lihat
 * AuthController). Tanpa penyesuaian ini mereka tidak bisa membuka panel sama
 * sekali, termasuk staf akunting yang justru memakai modul inventory tiap hari.
 */
class Login extends BaseLogin
{
    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Email atau Username')
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /** @return array<string, Component> */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getLoginFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getRememberFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    /**
     * Isian yang berbentuk alamat email dicocokkan ke kolom email, selain itu
     * ke kolom name -- aturan yang sama dengan AuthController.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $login = trim((string) ($data['login'] ?? ''));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

        return [
            $field => $login,
            'password' => $data['password'],
        ];
    }

    /**
     * Akun nonaktif tidak boleh masuk walau kata sandinya benar.
     *
     * Filament sudah menetapkan sesi saat pemeriksaan ini berjalan, jadi sesi
     * itu dibatalkan lagi -- kalau tidak, penolakan hanya tampak di layar
     * sementara penggunanya sebenarnya sudah masuk.
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => 'Username atau password salah.',
        ]);
    }

    public function authenticate(): ?\Filament\Http\Responses\Auth\Contracts\LoginResponse
    {
        $response = parent::authenticate();

        /** @var User|null $user */
        $user = Auth::user();

        if ($user && ! $user->is_active) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            throw ValidationException::withMessages([
                'data.login' => 'Akun ini sudah dinonaktifkan. Hubungi admin.',
            ]);
        }

        return $response;
    }
}
