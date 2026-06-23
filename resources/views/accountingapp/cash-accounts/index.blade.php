@extends('layouts.accountingapp', ['title' => 'Cash Accounts'])

@section('content')
  <h1 class="text-2xl font-bold mb-4">Cash Accounts</h1>

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
    <h2 class="panel-title mb-4">Tambah Cash Account</h2>
    <form method="POST" action="{{ route('accountingapp.cash-accounts.store') }}"
          class="grid grid-cols-1 md:grid-cols-5 gap-3">
      @csrf

      <div>
        <label class="form-label">Nama Cash Account</label>
        <input type="text" name="name" value="{{ old('name') }}"
               class="form-control" required>
      </div>

      <div>
        <label class="form-label">Tipe</label>
        <select name="type" class="form-control" required>
          <option value="cash" @selected(old('type', 'cash') === 'cash')>Tunai</option>
          <option value="bank" @selected(old('type') === 'bank')>Bank</option>
        </select>
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

      <div class="md:col-span-5 flex justify-end">
        <button type="submit" class="btn-primary">
          Simpan Cash Account
        </button>
      </div>
    </form>
  </div>

  <div class="table-card">
    <div class="table-card-head">Daftar Cash Accounts</div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="text-left px-4 py-2">Nama</th>
            <th class="text-left px-4 py-2">Tipe</th>
            <th class="text-left px-4 py-2">Deskripsi</th>
            <th class="text-left px-4 py-2">Status</th>
            <th class="text-left px-4 py-2">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($cashAccounts as $cashAccount)
            <tr class="border-t">
              <td class="px-4 py-2">{{ $cashAccount->name }}</td>
              <td class="px-4 py-2">{{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }}</td>
              <td class="px-4 py-2">{{ $cashAccount->description ?: '-' }}</td>
              <td class="px-4 py-2">{{ $cashAccount->is_active ? 'Aktif' : 'Nonaktif' }}</td>
              <td class="px-4 py-2">
                <div class="flex items-center gap-3">
                  <a href="{{ route('accountingapp.cash-accounts.edit', $cashAccount->id) }}"
                     class="btn-link">
                    Edit
                  </a>
                  <form method="POST" action="{{ route('accountingapp.cash-accounts.destroy', $cashAccount->id) }}"
                        onsubmit="return confirm('Hapus cash account ini?')">
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
              <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                Belum ada data cash account.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4">
      {{ $cashAccounts->links() }}
    </div>
  </div>
@endsection

