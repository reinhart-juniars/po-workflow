@extends('layouts.accountingapp', ['title' => 'Kategori Pengeluaran'])

@section('content')
  <h1 class="text-2xl font-bold mb-4">Kategori Pengeluaran</h1>

  <div class="section-card mb-6">
    <h2 class="panel-title mb-4">Tambah Kategori Pengeluaran</h2>
    <form method="POST" action="{{ route('accountingapp.categories.store') }}"
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

      <div>
        <label class="form-label">Mode Pengeluaran</label>
        <select name="expense_mode" class="form-control" required>
          <option value="direct_expense" @selected(old('expense_mode', 'direct_expense') === 'direct_expense')>
            Pengeluaran Langsung
          </option>
          <option value="inventory_purchase" @selected(old('expense_mode') === 'inventory_purchase')>
            Pembelian Stok
          </option>
          <option value="fixed_asset" @selected(old('expense_mode') === 'fixed_asset')>
            Aktiva Tetap
          </option>
        </select>
      </div>

      <div class="flex items-end gap-3">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" name="include_hpp" value="1" @checked(old('include_hpp'))>
          <span class="text-sm text-gray-700">Masuk HPP</span>
        </label>

        <label class="inline-flex items-center gap-2">
          <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
          <span class="text-sm text-gray-700">Aktif</span>
        </label>
      </div>

      <div class="md:col-span-4 flex justify-end">
        <button type="submit" class="btn-primary">
          Simpan Kategori Pengeluaran
        </button>
      </div>
    </form>
  </div>

  <div class="table-card">
    <div class="table-card-head">Daftar Kategori Pengeluaran</div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="text-left px-4 py-2">Nama</th>
            <th class="text-left px-4 py-2">Deskripsi</th>
            <th class="text-left px-4 py-2">Mode</th>
            <th class="text-left px-4 py-2">Masuk HPP</th>
            <th class="text-left px-4 py-2">Status</th>
            <th class="text-left px-4 py-2">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($categories as $category)
            <tr class="border-t align-top">
              <td class="px-4 py-2">
                <input form="update-category-{{ $category->id }}" type="text" name="name"
                       value="{{ $category->name }}" class="form-control" required>
              </td>
              <td class="px-4 py-2">
                <input form="update-category-{{ $category->id }}" type="text" name="description"
                       value="{{ $category->description }}" class="form-control">
              </td>
              <td class="px-4 py-2">
                <select form="update-category-{{ $category->id }}" name="expense_mode"
                        class="form-control" required>
                  <option value="direct_expense" @selected($category->expense_mode === 'direct_expense')>
                    Pengeluaran Langsung
                  </option>
                  <option value="inventory_purchase" @selected($category->expense_mode === 'inventory_purchase')>
                    Pembelian Stok
                  </option>
                  <option value="fixed_asset" @selected($category->expense_mode === 'fixed_asset')>
                    Aktiva Tetap
                  </option>
                </select>
              </td>
              <td class="px-4 py-2">
                <label class="inline-flex items-center gap-2">
                  <input form="update-category-{{ $category->id }}" type="checkbox" name="include_hpp" value="1"
                         @checked($category->include_hpp)>
                  <span>{{ $category->include_hpp ? 'Ya' : 'Tidak' }}</span>
                </label>
              </td>
              <td class="px-4 py-2">
                <label class="inline-flex items-center gap-2">
                  <input form="update-category-{{ $category->id }}" type="checkbox" name="is_active" value="1"
                         @checked($category->is_active)>
                  <span>{{ $category->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                </label>
              </td>
              <td class="px-4 py-2">
                <div class="flex gap-2">
                  <form id="update-category-{{ $category->id }}" method="POST"
                        action="{{ route('accountingapp.categories.update', $category->id) }}">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn-warning">
                      Update
                    </button>
                  </form>

                  <form method="POST" action="{{ route('accountingapp.categories.destroy', $category->id) }}"
                        onsubmit="return confirm('Hapus kategori ini?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-danger">
                      Hapus
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="6" class="px-4 py-6 text-center text-gray-500">
                Belum ada data kategori pengeluaran.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4">
      {{ $categories->links() }}
    </div>
  </div>
@endsection

