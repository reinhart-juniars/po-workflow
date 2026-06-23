@extends('layouts.accountingapp', ['title' => 'Kategori Pemasukan'])

@section('content')
  <h1 class="text-2xl font-bold mb-4">Kategori Pemasukan</h1>

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

  <div class="section-card mb-6">
    <h2 class="panel-title mb-4">Tambah Kategori Pemasukan</h2>
    <form method="POST" action="{{ route('accountingapp.income-categories.store') }}"
          class="grid grid-cols-1 md:grid-cols-4 gap-3">
      @csrf

      <div>
        <label class="form-label">Nama Kategori</label>
        <input type="text" name="name" value="{{ old('name') }}"
               class="form-control" required>
      </div>

      <div class="md:col-span-2">
        <label class="form-label">Deskripsi</label>
        <input type="text" name="description" value="{{ old('description') }}"
               class="form-control">
      </div>

      <div class="flex items-end gap-3">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
          <span class="text-sm text-gray-700">Aktif</span>
        </label>
      </div>

      <div class="md:col-span-4 flex justify-end">
        <button type="submit" class="btn-primary">
          Simpan Kategori Pemasukan
        </button>
      </div>
    </form>
  </div>

  <div class="table-card">
    <div class="table-card-head">Daftar Kategori Pemasukan</div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="text-left px-4 py-2">Nama</th>
            <th class="text-left px-4 py-2">Deskripsi</th>
            <th class="text-left px-4 py-2">Status</th>
            <th class="text-left px-4 py-2">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($incomeCategories as $incomeCategory)
            <tr class="border-t">
              <td class="px-4 py-2">{{ $incomeCategory->name }}</td>
              <td class="px-4 py-2">{{ $incomeCategory->description ?: '-' }}</td>
              <td class="px-4 py-2">{{ $incomeCategory->is_active ? 'Aktif' : 'Nonaktif' }}</td>
              <td class="px-4 py-2">
                <div class="flex items-center gap-3">
                  <a href="{{ route('accountingapp.income-categories.edit', $incomeCategory->id) }}"
                     class="btn-link">
                    Edit
                  </a>
                  <form method="POST" action="{{ route('accountingapp.income-categories.destroy', $incomeCategory->id) }}"
                        onsubmit="return confirm('Hapus kategori pemasukan ini?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-link-danger">
                      Hapus
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="px-4 py-6 text-center text-gray-500">
                Belum ada data kategori pemasukan.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4">
      {{ $incomeCategories->links() }}
    </div>
  </div>
@endsection

