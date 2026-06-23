@extends('layouts.adminapp', ['title' => 'Tambah Customer'])

@section('content')
<h1 class="text-2xl font-bold mb-4">Tambah Customer</h1>

@if ($errors->any())
  <div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-700 text-sm">
    <b>Terjadi kesalahan:</b>
    <ul class="list-disc pl-4">
      @foreach ($errors->all() as $e)
        <li>{{ $e }}</li>
      @endforeach
    </ul>
  </div>
@endif

<form action="{{ route('adminapp.customers.store') }}" method="POST" class="space-y-4 max-w-lg">
  @csrf

  <div>
    <label class="block text-sm text-gray-600 mb-1">Nama</label>
    <input type="text" name="name"
           value="{{ old('name') }}"
           class="w-full border rounded px-3 py-2 text-sm" required>
  </div>

  <div>
    <label class="block text-sm text-gray-600 mb-1">No HP</label>
    <input type="text" name="phone"
           value="{{ old('phone') }}"
           class="w-full border rounded px-3 py-2 text-sm">
  </div>

  <div>
    <label class="block text-sm text-gray-600 mb-1">Alamat</label>
    <textarea name="address" rows="2"
              class="w-full border rounded px-3 py-2 text-sm">{{ old('address') }}</textarea>
  </div>

  <div>
    <label class="block text-sm text-gray-600 mb-1">Area</label>
    <select name="area_id" class="w-full border rounded px-3 py-2 text-sm" required>
      <option value="">-- Pilih Area --</option>
      @foreach ($areas as $id => $name)
        <option value="{{ $id }}" @selected(old('area_id') == $id)>
            {{ $name }}
        </option>
      @endforeach
    </select>
  </div>

  <div class="flex items-center gap-2">
    <input type="checkbox" name="active" value="1"
           id="active"
           @checked(old('active', true))>
    <label for="active" class="text-sm text-gray-700">Aktif</label>
  </div>

  <div class="pt-2">
    <button class="px-4 py-2 rounded bg-green-600 text-white text-sm">
      Simpan
    </button>
  </div>
</form>
@endsection
