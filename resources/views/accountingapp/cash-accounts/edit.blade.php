@extends('layouts.accountingapp', ['title' => 'Edit Cash Account'])

@section('content')
  <h1 class="text-2xl font-bold mb-4">Edit Cash Account</h1>

  @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-4">
      <p class="font-semibold mb-1">Gagal menyimpan data:</p>
      <ul class="list-disc pl-5 text-sm">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="section-card">
    <form method="POST" action="{{ route('accountingapp.cash-accounts.update', $cashAccount->id) }}"
          class="grid grid-cols-1 md:grid-cols-4 gap-3">
      @csrf
      @method('PUT')

      <div>
        <label class="form-label">Nama Cash Account</label>
        <input type="text" name="name" value="{{ old('name', $cashAccount->name) }}"
               class="form-control" required>
      </div>

      <div>
        <label class="form-label">Tipe</label>
        <select name="type" class="form-control" required>
          <option value="cash" @selected(old('type', $cashAccount->type) === 'cash')>Tunai</option>
          <option value="bank" @selected(old('type', $cashAccount->type) === 'bank')>Bank</option>
        </select>
      </div>

      <div class="md:col-span-2">
        <label class="form-label">Deskripsi</label>
        <input type="text" name="description" value="{{ old('description', $cashAccount->description) }}"
               class="form-control">
      </div>

      <div class="md:col-span-4">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $cashAccount->is_active))>
          <span class="text-sm text-gray-700">Aktif</span>
        </label>
      </div>

      <div class="md:col-span-4 flex justify-end gap-3">
        <a href="{{ route('accountingapp.cash-accounts.index') }}"
           class="btn-outline">
          Batal
        </a>
        <button type="submit" class="btn-primary">
          Simpan Perubahan
        </button>
      </div>
    </form>
  </div>
@endsection

