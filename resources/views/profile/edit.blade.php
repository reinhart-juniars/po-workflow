@extends('layouts.profile', ['title' => 'Profil'])

@section('content')
  @php
      $user = auth()->user();
      $backRoute = $user->preferredDashboardRouteName();
      $backUrl = $backRoute ? route($backRoute) : '/dashboard';
  @endphp

  <div class="mb-4">
      <a href="{{ $backUrl }}"
          class="inline-block px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded shadow text-sm">
          ← Kembali ke Dashboard
      </a>
  </div>

  <h1 class="text-2xl font-bold mb-6">Profil Akun</h1>

  {{-- Info user (read-only) --}}
  <div class="bg-white rounded-lg shadow p-4 mb-6">
    <h2 class="font-semibold text-sm mb-3">Informasi Akun</h2>

    <dl class="text-sm space-y-2">
      <div class="flex justify-between">
        <dt class="text-gray-500">Nama (username)</dt>
        <dd class="font-mono text-gray-800">{{ $user->name }}</dd>
      </div>
      <div class="flex justify-between">
        <dt class="text-gray-500">Email</dt>
        <dd class="text-gray-800">
          @if($user->email)
            {{ $user->email }}
          @else
            <span class="text-xs italic text-gray-400">(tidak ada email)</span>
          @endif
        </dd>
      </div>
      <div class="flex justify-between">
        <dt class="text-gray-500">Role</dt>
        <dd class="text-gray-800">
          {{ $user->getRoleNames()->implode(', ') }}
        </dd>
      </div>
    </dl>

    <p class="mt-3 text-xs text-gray-500">
      Nama digunakan sebagai username login dan saat ini tidak dapat diubah sendiri.
      Hubungi Owner jika ada koreksi nama akun.
    </p>
  </div>

  {{-- Form ganti password --}}
  <div class="bg-white rounded-lg shadow p-4">
    <h2 class="font-semibold text-sm mb-3">Ganti Password</h2>

    <form method="POST" action="{{ route('profile.password.update') }}" class="space-y-4">
      @csrf
      @method('PUT')

      <div>
        <label class="block text-sm text-gray-600 mb-1">Password saat ini</label>
        <input type="password" name="current_password"
               class="w-full border rounded px-3 py-2 text-sm"
               required>
        @error('current_password')
          <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label class="block text-sm text-gray-600 mb-1">Password baru</label>
        <input type="password" name="password"
               class="w-full border rounded px-3 py-2 text-sm"
               required>
        @error('password')
          <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label class="block text-sm text-gray-600 mb-1">Konfirmasi password baru</label>
        <input type="password" name="password_confirmation"
               class="w-full border rounded px-3 py-2 text-sm"
               required>
      </div>

      <div class="pt-2">
        <button class="px-4 py-2 rounded bg-indigo-600 text-white text-sm">
          Simpan Password Baru
        </button>
      </div>
    </form>
  </div>
@endsection
