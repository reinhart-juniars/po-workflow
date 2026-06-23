@extends('layouts.accountingapp', ['title' => 'Master Item'])

@section('content')
  <h1 class="text-2xl font-bold mb-4">Master Item</h1>

  <div class="section-card mb-6">
    <h2 class="panel-title mb-4">Tambah Item</h2>
    <form method="POST" action="{{ route('accountingapp.inventory-items.store') }}"
          class="grid grid-cols-1 md:grid-cols-6 gap-3">
      @csrf

      <div>
        <label class="form-label">Nama Item</label>
        <input type="text" name="name" value="{{ old('name') }}" class="form-control" required>
      </div>

      <div>
        <label class="form-label">Unit</label>
        <input type="text" name="unit" value="{{ old('unit') }}" class="form-control" required>
      </div>

      <div class="md:col-span-2">
        <label class="form-label">Kategori</label>
        <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 px-3 py-2">
          @foreach($categoryOptions as $value => $label)
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
              <input type="radio" name="category" value="{{ $value }}"
                     @checked(old('category', \App\Models\InventoryItem::CATEGORY_RAW_MATERIAL) === $value) required>
              <span>{{ $label }}</span>
            </label>
          @endforeach
        </div>
      </div>

      <div class="md:col-span-2">
        <label class="form-label">Deskripsi</label>
        <input type="text" name="description" value="{{ old('description') }}" class="form-control">
      </div>

      <div class="flex items-end gap-3">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
          <span class="text-sm text-gray-700">Aktif</span>
        </label>
      </div>

      <div class="md:col-span-5 flex justify-end">
        <button type="submit" class="btn-primary">Simpan Item</button>
      </div>
    </form>
  </div>

  <div class="table-card">
    <div class="table-card-head">Daftar Item</div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="text-left px-4 py-2">Nama</th>
            <th class="text-left px-4 py-2">Unit</th>
            <th class="text-left px-4 py-2">Kategori</th>
            <th class="text-left px-4 py-2">Deskripsi</th>
            <th class="text-left px-4 py-2">Status</th>
            <th class="text-left px-4 py-2">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($items as $item)
            <tr class="border-t align-top">
              <td class="px-4 py-2">
                <input form="update-item-{{ $item->id }}" type="text" name="name" value="{{ $item->name }}"
                       class="form-control" required>
              </td>
              <td class="px-4 py-2">
                <input form="update-item-{{ $item->id }}" type="text" name="unit" value="{{ $item->unit }}"
                       class="form-control" required>
              </td>
              <td class="px-4 py-2">
                <div class="space-y-1">
                  @foreach($categoryOptions as $value => $label)
                    <label class="flex items-center gap-2 whitespace-nowrap text-sm">
                      <input form="update-item-{{ $item->id }}" type="radio" name="category" value="{{ $value }}"
                             @checked(($item->category ?? \App\Models\InventoryItem::CATEGORY_RAW_MATERIAL) === $value) required>
                      <span>{{ $label }}</span>
                    </label>
                  @endforeach
                </div>
              </td>
              <td class="px-4 py-2">
                <input form="update-item-{{ $item->id }}" type="text" name="description" value="{{ $item->description }}"
                       class="form-control">
                @php
                  $usageBadges = collect([
                    'Opening' => (int) ($item->openings_count ?? 0),
                    'Pembelian' => (int) ($item->purchases_count ?? 0),
                    'Opname' => (int) ($item->opnames_count ?? 0),
                  ])->filter(fn ($count) => $count > 0);
                @endphp
                @if($usageBadges->isNotEmpty())
                  <div class="mt-2 flex flex-wrap gap-1 text-xs text-slate-600">
                    @foreach($usageBadges as $label => $count)
                      <span class="rounded bg-slate-100 px-2 py-0.5">{{ $label }}: {{ $count }}</span>
                    @endforeach
                  </div>
                @endif
              </td>
              <td class="px-4 py-2">
                <label class="inline-flex items-center gap-2">
                  <input form="update-item-{{ $item->id }}" type="checkbox" name="is_active" value="1" @checked($item->is_active)>
                  <span>{{ $item->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                </label>
              </td>
              <td class="px-4 py-2">
                <div class="flex gap-2">
                  <form id="update-item-{{ $item->id }}" method="POST"
                        action="{{ route('accountingapp.inventory-items.update', $item) }}">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn-warning">Update</button>
                  </form>
                  <form method="POST" action="{{ route('accountingapp.inventory-items.destroy', $item) }}"
                        onsubmit="return confirm('Hapus item ini?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-danger">Hapus</button>
                  </form>
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="6" class="px-4 py-6 text-center text-gray-500">Belum ada item inventory.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4">
      {{ $items->links() }}
    </div>
  </div>
@endsection

