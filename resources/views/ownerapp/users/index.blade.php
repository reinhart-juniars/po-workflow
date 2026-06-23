@extends('layouts.ownerapp', ['title' => 'Master User'])

@section('content')
    @php
        $newUserRoles = collect(old('roles', []))->map(fn($role) => (string) $role)->all();
        $newUserRoleRows = count($newUserRoles) > 0 ? $newUserRoles : [''];
    @endphp

    <div class="page-toolbar">
        <div>
            <h1 class="section-title">Master User</h1>
            <p class="section-subtitle">Kelola akun user, role, status aktif, dan reset password.</p>
        </div>
        <button id="toggleAddUserBtn" type="button" class="btn-primary">+ Tambah User Baru</button>
    </div>

    <section id="addUserForm" class="form-shell mt-4 hidden">
        <h2 class="text-lg font-semibold text-slate-900">Tambah User Baru</h2>
        <form method="POST" action="{{ route('ownerapp.users.store') }}" class="user-add-form">
            @csrf

            <div>
                <label for="new_name" class="mb-1.5 block">Nama</label>
                <input id="new_name" name="name" value="{{ old('name') }}" required>
            </div>

            <div>
                <label for="new_email" class="mb-1.5 block">Email (opsional)</label>
                <input id="new_email" name="email" type="email" value="{{ old('email') }}">
            </div>

            <div class="md:col-span-2">
                <label class="mb-1.5 block">Role</label>
                <div class="role-builder max-w-md" data-role-select-group
                    data-available-roles='@json(array_values($availableRoles))'>
                    <div data-role-select-list class="role-builder-list">
                        @foreach ($newUserRoleRows as $selectedRole)
                            <div class="role-builder-row" data-role-select-row>
                                <select name="roles[]" class="role-builder-select" data-role-select>
                                    <option value="">Pilih role</option>
                                    @foreach ($availableRoles as $r)
                                        <option value="{{ $r }}" @selected($selectedRole === $r)>{{ ucfirst($r) }}</option>
                                    @endforeach
                                </select>
                                <button type="button" class="role-builder-remove hidden" data-role-remove
                                    aria-label="Hapus role">&times;</button>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" class="role-builder-add" data-role-add>+ Tambah Role</button>
                        <span class="role-builder-meta">Role berikutnya otomatis mengecualikan pilihan yang sudah dipakai.</span>
                    </div>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                    Pilih minimal 1 role. Multi-role hanya untuk selain owner; role owner berdiri sendiri.
                </p>
            </div>

            <div class="flex items-center">
                <label for="new_active" class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input id="new_active" type="checkbox" name="is_active" value="1" @checked(old('is_active', '1'))
                        class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500/30">
                    User Aktif
                </label>
            </div>

            <div class="flex items-end">
                <button class="btn-primary min-h-[38px] w-full px-4 py-2 text-sm">Simpan</button>
            </div>
        </form>
    </section>

    <section class="table-shell mt-4">
        <div class="table-head">Daftar User</div>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($users as $user)
                        @php
                            $roles = $user->roles->pluck('name')->sort()->values()->all();
                            $isSelf = auth()->id() === $user->id;
                            $isOwner = in_array('owner', $roles, true);
                        @endphp

                        <tr>
                            <td class="font-semibold text-slate-800">{{ $user->name }}</td>
                            <td>{{ $user->email ?? '-' }}</td>

                            <td class="user-role-cell">
                                <form method="POST" action="{{ route('ownerapp.users.update', $user) }}"
                                    class="user-role-form">
                                    @csrf
                                    @method('PUT')

                                    <div class="user-role-editor">
                                        <div class="role-builder max-w-[240px]" data-role-select-group
                                            data-available-roles='@json(array_values($availableRoles))'>
                                            <div data-role-select-list class="role-builder-list">
                                                @foreach ($roles as $selectedRole)
                                                    <div class="role-builder-row" data-role-select-row>
                                                        <select name="roles[]" class="role-builder-select" data-role-select>
                                                            <option value="">Pilih role</option>
                                                            @foreach ($availableRoles as $r)
                                                                <option value="{{ $r }}" @selected($selectedRole === $r)>{{ ucfirst($r) }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button type="button" class="role-builder-remove hidden"
                                                            data-role-remove aria-label="Hapus role">&times;</button>
                                                    </div>
                                                @endforeach
                                            </div>
                                            <div class="mt-2">
                                                <button type="button" class="role-builder-add" data-role-add>+ Tambah
                                                    Role</button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="shrink-0">
                                        <button class="user-role-submit">Simpan</button>
                                    </div>
                                </form>
                            </td>

                            <td>
                                <span
                                    class="chip {{ $user->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                                    {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>

                            <td>
                                <div class="user-action-stack">
                                    <form method="POST" action="{{ route('ownerapp.users.reset-password', $user) }}"
                                        onsubmit="return confirm('Reset password user ini?')" class="w-full">
                                        @csrf
                                        @method('PATCH')
                                        <button
                                            class="user-action-btn border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300 hover:bg-amber-100">
                                            Reset PW
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('ownerapp.users.deactivate', $user) }}" class="w-full">
                                        @csrf
                                        @method('PATCH')
                                        <button class="user-action-btn">
                                            {{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </button>
                                    </form>

                                    @if (!$isOwner && !$isSelf)
                                        <form method="POST" action="{{ route('ownerapp.users.destroy', $user) }}"
                                            onsubmit="return confirm('Yakin hapus user ini?')" class="w-full">
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                class="user-action-btn border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100">
                                                Hapus
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-sm text-slate-500">Belum ada data user.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <script>
        const btn = document.getElementById('toggleAddUserBtn');
        const form = document.getElementById('addUserForm');
        const shouldOpenAddUserForm = @json(old('name') !== null || old('email') !== null || !empty($newUserRoles));
        const roleGroups = document.querySelectorAll('[data-role-select-group]');

        if (btn && form) {
            if (shouldOpenAddUserForm) {
                form.classList.remove('hidden');
                btn.textContent = '- Tutup Form User Baru';
            }

            btn.addEventListener('click', () => {
                const isHidden = form.classList.contains('hidden');

                if (isHidden) {
                    form.classList.remove('hidden');
                    btn.textContent = '- Tutup Form User Baru';
                } else {
                    form.classList.add('hidden');
                    btn.textContent = '+ Tambah User Baru';
                }
            });
        }

        roleGroups.forEach((group) => {
            const availableRoles = JSON.parse(group.dataset.availableRoles || '[]');
            const list = group.querySelector('[data-role-select-list]');
            const addButton = group.querySelector('[data-role-add]');

            if (!list || !addButton) {
                return;
            }

            const createOptionMarkup = (role, selectedRole) => {
                const selectedAttr = role === selectedRole ? ' selected' : '';
                const label = role.charAt(0).toUpperCase() + role.slice(1);

                return `<option value="${role}"${selectedAttr}>${label}</option>`;
            };

            const createRow = (selectedRole = '') => {
                const row = document.createElement('div');
                row.className = 'role-builder-row';
                row.dataset.roleSelectRow = '';
                row.innerHTML = `
      <select name="roles[]" class="role-builder-select" data-role-select>
        <option value="">Pilih role</option>
        ${availableRoles.map((role) => createOptionMarkup(role, selectedRole)).join('')}
      </select>
      <button type="button" class="role-builder-remove hidden" data-role-remove aria-label="Hapus role">&times;</button>
    `;

                return row;
            };

            const getRows = () => Array.from(list.querySelectorAll('[data-role-select-row]'));
            const getSelects = () => getRows().map((row) => row.querySelector('[data-role-select]')).filter(Boolean);
            const getSelectedRoles = () => getSelects()
                .map((select) => select.value)
                .filter((value) => value !== '');

            const syncRoleRows = () => {
                const rows = getRows();
                const selectedRoles = getSelectedRoles();
                const ownerSelected = selectedRoles.includes('owner');

                rows.forEach((row) => {
                    const select = row.querySelector('[data-role-select]');
                    const removeButton = row.querySelector('[data-role-remove]');
                    const currentValue = select.value;

                    const allowedRoles = availableRoles.filter((role) => {
                        if (role === currentValue) {
                            return true;
                        }

                        if (selectedRoles.includes(role)) {
                            return false;
                        }

                        if (role === 'owner') {
                            return selectedRoles.length === 0;
                        }

                        if (ownerSelected && currentValue !== 'owner') {
                            return false;
                        }

                        return true;
                    });

                    select.innerHTML = ['<option value="">Pilih role</option>']
                        .concat(allowedRoles.map((role) => createOptionMarkup(role, currentValue)))
                        .join('');
                    select.value = currentValue;

                    if (select.value !== currentValue) {
                        select.value = '';
                    }

                    removeButton.classList.toggle('hidden', rows.length === 1);
                });

                const hasEmptyRow = getSelects().some((select) => select.value === '');
                const remainingRoles = availableRoles.filter((role) => !selectedRoles.includes(role));
                const addDisabled = ownerSelected || hasEmptyRow || remainingRoles.length === 0;

                addButton.classList.toggle('hidden', ownerSelected);
                addButton.disabled = addDisabled;
                addButton.classList.toggle('opacity-50', addDisabled);
                addButton.classList.toggle('cursor-not-allowed', addDisabled);
            };

            list.addEventListener('change', (event) => {
                if (event.target.matches('[data-role-select]')) {
                    syncRoleRows();
                }
            });

            list.addEventListener('click', (event) => {
                if (!event.target.matches('[data-role-remove]')) {
                    return;
                }

                const rows = getRows();
                if (rows.length === 1) {
                    return;
                }

                event.target.closest('[data-role-select-row]')?.remove();
                syncRoleRows();
            });

            addButton.addEventListener('click', () => {
                const selectedRoles = getSelectedRoles();
                const ownerSelected = selectedRoles.includes('owner');

                if (ownerSelected) {
                    return;
                }

                list.appendChild(createRow());
                syncRoleRows();
            });

            if (getRows().length === 0) {
                list.appendChild(createRow());
            }

            syncRoleRows();
        });
    </script>
@endsection
