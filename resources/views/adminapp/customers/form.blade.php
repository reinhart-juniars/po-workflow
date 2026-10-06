@extends('layouts.adminapp')

@section('title', $mode === 'create' ? 'Tambah Customer' : 'Edit Customer')

@section('content')
<div class="page-toolbar">
    <div>
        <h1 class="section-title">{{ $mode === 'create' ? 'Tambah Customer Baru' : 'Edit Customer' }}</h1>
        <p class="section-subtitle">Data customer dipakai untuk alamat kirim, area, dan informasi penerima order.</p>
    </div>
</div>

@if($errors->any())
    <div class="flash-error mt-4">
        <ul class="list-disc pl-5">
            @foreach($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST"
      action="{{ $mode === 'create'
                    ? route('adminapp.customers.store')
                    : route('adminapp.customers.update', $customer) }}"
      class="form-shell mt-4 space-y-5">
    @csrf
    @if($mode === 'edit')
        @method('PUT')
    @endif

    <div class="form-grid">
        <div>
            <label for="name" class="mb-1.5 block">Nama Customer</label>
            <input id="name" type="text" name="name" value="{{ old('name', $customer->name) }}" required>
        </div>

        <div>
            <label for="phone" class="mb-1.5 block">No. Telepon</label>
            <input id="phone" type="text" name="phone" value="{{ old('phone', $customer->phone) }}">
        </div>

        <div class="md:col-span-2">
            <label for="address" class="mb-1.5 block">Alamat</label>
            <textarea id="address" name="address" rows="3">{{ old('address', $customer->address) }}</textarea>
        </div>

        <div>
            <label for="area_id" class="mb-1.5 block">Area</label>
            <select id="area_id" name="area_id" required>
                <option value="">-- Pilih Area --</option>
                @foreach ($areas as $id => $name)
                    <option value="{{ $id }}" @selected(old('area_id', $customer->area_id ?? null) == $id)>
                        {{ $name }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="flex flex-wrap gap-4">
        <label for="active" class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
            <input id="active" type="checkbox" name="active" value="1" @checked(old('active', $customer->active ?? true)) class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500/30">
            Aktif
        </label>

        <label for="is_lapak" class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
            <input id="is_lapak" type="checkbox" name="is_lapak" value="1" @checked(old('is_lapak', $customer->is_lapak ?? false)) class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500/30">
            Lapak
        </label>
    </div>

    <div class="flex flex-wrap gap-2">
        <button type="submit" class="btn-primary">Simpan</button>
        <a href="{{ route('adminapp.customers.index') }}" class="btn-ghost">Batal</a>
    </div>
</form>
@endsection
