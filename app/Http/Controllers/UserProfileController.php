<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    // Tampilkan halaman profil + form ganti password
    public function edit(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        return view('profile.edit', compact('user'));
    }

    // Proses ganti password
    public function updatePassword(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'current_password'      => ['required'],
            'password'              => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        // verifikasi password lama
        if (! Hash::check($data['current_password'], $user->password)) {
            return back()
                ->withErrors(['current_password' => 'Password saat ini tidak sesuai.'])
                ->withInput();
        }

        // update password
        $user->password = Hash::make($data['password']);
        $user->force_password_change = false;
        $user->save();

        // catat audit log (tanpa menyimpan password!)
        AuditLog::create([
            'user_id'           => $user->id,
            'entity'            => 'user',
            'entity_id'         => $user->id,
            'purchase_order_id' => null,
            'action'            => 'password_changed',
            'message'           => sprintf(
                'User %s mengubah password akunnya pada %s',
                $user->name,
                now()->format('d-m-Y H:i')
            ),
            'before_json'       => null,
            'after_json'        => null,
            'ip_address'        => $request->ip(),
        ]);

        return back()->with('success', 'Password berhasil diperbarui.');
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
        ]);

        if ($data['email'] !== $user->email) {
            $user->email_verified_at = null;
        }

        $user->fill($data)->save();

        return redirect('/profile');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        /** @var User $user */
        $user = $request->user();

        Auth::logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
