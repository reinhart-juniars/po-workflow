<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class OwnerUserController extends Controller
{
    public function index()
    {
        $users = User::whereDoesntHave('roles', function ($query) {
            $query->where('name', 'superadmin');
        })
            ->with('roles')
            ->orderBy('name')
            ->get();

        $availableRoles = User::manageableRoles();

        return view('ownerapp.users.index', compact('users', 'availableRoles'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', 'in:owner,admin,accounting,sales,production,delivery'],
            'is_active' => ['nullable'],
        ]);
        $roles = collect($data['roles'])->unique()->values()->all();

        if (in_array('owner', $roles, true) && count($roles) > 1) {
            return back()
                ->withInput()
                ->with('error', 'Role owner tidak boleh digabung dengan role lain.');
        }

        $generatedPassword = Str::random(10);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($generatedPassword),
            'is_active' => $request->boolean('is_active'),
            'force_password_change' => true,
        ]);

        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
        }

        $user->syncRoles($roles);

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'user',
            'entity_id' => $user->id,
            'action' => 'user_created',
            'message' => sprintf(
                'Membuat user %s (%s) dengan role %s',
                $user->name,
                $user->email ?? '(tidak ada email)',
                implode(', ', $roles)
            ),
            'ip_address' => $request->ip(),
        ]);

        return back()->with(
            'success',
            "User berhasil dibuat. Password sementara: {$generatedPassword}"
        );
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', 'in:owner,admin,accounting,sales,production,delivery'],
        ]);
        $newRoles = collect($data['roles'])->unique()->values()->all();

        if ($user->hasRole('superadmin')) {
            return back()->with('error', 'User superadmin tidak boleh diubah dari sini.');
        }

        if (in_array('owner', $newRoles, true) && count($newRoles) > 1) {
            return back()
                ->withInput()
                ->with('error', 'Role owner tidak boleh digabung dengan role lain.');
        }

        if ($user->hasRole('owner') && ! in_array('owner', $newRoles, true)) {
            return back()
                ->withInput()
                ->with('error', 'Role owner wajib tetap dipilih untuk akun owner.');
        }

        if (
            Auth::id() === $user->id
            && Auth::user()?->hasRole('owner')
            && ! in_array('owner', $newRoles, true)
        ) {
            return back()
                ->withInput()
                ->with('error', 'Akun sendiri wajib tetap memiliki role owner.');
        }

        $oldRoles = $user->getRoleNames()->sort()->values()->all();
        $emailLabel = $user->email ?? '(tidak ada email)';

        foreach ($newRoles as $role) {
            Role::findOrCreate($role, 'web');
        }

        $before = [
            'roles' => $oldRoles,
        ];

        $user->syncRoles($newRoles);
        $user->load('roles');

        $after = [
            'roles' => $user->getRoleNames()->sort()->values()->all(),
        ];

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'user',
            'entity_id' => $user->id,
            'purchase_order_id' => null,
            'action' => 'user_role_updated',
            'message' => sprintf(
                'Mengubah role user %s (%s) dari %s menjadi %s',
                $user->name,
                $emailLabel,
                implode(', ', $oldRoles) ?: '-',
                implode(', ', $newRoles),
            ),
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', 'Role user berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return back()->with('error', 'Tidak bisa menghapus akun sendiri.');
        }

        if ($user->hasRole('owner')) {
            return back()->with('error', 'Akun owner tidak boleh dihapus.');
        }

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->toArray(),
        ];

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'user',
            'entity_id' => $user->id,
            'purchase_order_id' => null,
            'action' => 'user_deleted',
            'message' => sprintf(
                'Menghapus user: %s (%s)',
                $user->name,
                $user->email ?? '(tidak ada email)'
            ),
            'before_json' => $before,
            'after_json' => null,
            'ip_address' => $request->ip(),
        ]);

        $user->delete();

        return redirect()
            ->route('ownerapp.users.index')
            ->with('success', 'User berhasil dihapus.');
    }

    public function resetPassword(User $user)
    {
        $newPassword = Str::random(10);

        $user->update([
            'password' => Hash::make($newPassword),
            'force_password_change' => true,
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'user',
            'entity_id' => $user->id,
            'action' => 'reset_password',
            'message' => sprintf(
                'User %s reset password user %s pada %s',
                Auth::user()->name,
                $user->name,
                now()->format('d-m-Y H:i')
            ),
            'ip_address' => request()->ip(),
        ]);

        return back()->with(
            'success',
            "Password user {$user->name} berhasil direset. Password sementara: {$newPassword}"
        );
    }

    public function deactivate(User $user)
    {
        $user->update([
            'is_active' => ! $user->is_active,
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'user',
            'entity_id' => $user->id,
            'action' => $user->is_active ? 'activated' : 'deactivated',
            'message' => sprintf(
                'User %s %s user %s pada %s',
                Auth::user()->name,
                $user->is_active ? 'mengaktifkan' : 'menonaktifkan',
                $user->name,
                now()->format('d-m-Y H:i')
            ),
            'ip_address' => request()->ip(),
        ]);

        return back()->with('success', 'Status user berhasil diperbarui.');
    }
}
