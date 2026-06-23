@extends('layouts.accountingapp', ['title' => 'Stock Opname'])

@section('content')
  @php
    $activeTab = 'filter';
    foreach (['inventory_item_id', 'opname_date', 'total_cost', 'notes'] as $field) {
        if ($errors->has($field)) {
            $activeTab = 'form';
            break;
        }
    }
  @endphp

  <h1 class="text-2xl font-bold mb-4">Stock Opname</h1>

  <div class="tab-card-shell js-tab-card" data-active-tab="{{ $activeTab }}">
    <div class="panel-head">
      <div class="inline-flex items-center gap-1 rounded-lg bg-gray-100 p-1">
        <button type="button" data-tab-trigger="form"
                class="tab-trigger text-gray-600 hover:text-gray-800">
          Tambah Stock Opname
        </button>
        <button type="button" data-tab-trigger="filter"
                class="tab-trigger text-gray-600 hover:text-gray-800">
          Filter Data
        </button>
      </div>
    </div>

    <div class="p-5" data-tab-panel="form">
      <form method="POST" action="{{ route('accountingapp.stock-opnames.store') }}"
            class="grid grid-cols-1 md:grid-cols-5 gap-3">
        @csrf

        <div>
          <label class="form-label">Item</label>
          <select name="inventory_item_id" class="form-control" required>
            <option value="">Pilih Item</option>
            @foreach($items as $item)
              <option value="{{ $item->id }}" @selected(old('inventory_item_id') == $item->id)>
                {{ $item->name }} ({{ $item->unit }}) - {{ $item->categoryLabel() }}
              </option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="form-label">Tanggal Opname</label>
          <input type="date" name="opname_date" value="{{ old('opname_date', now()->toDateString()) }}"
                 class="form-control" required>
        </div>

        <div>
          <label class="form-label">Total Cost</label>
          <input type="number" name="total_cost" min="0" step="0.01" value="{{ old('total_cost') }}"
                 class="form-control" required>
        </div>

        <div class="md:col-span-5">
          <label class="form-label">Catatan</label>
          <textarea name="notes" rows="2" class="form-control">{{ old('notes') }}</textarea>
        </div>

        <div class="md:col-span-5 flex justify-end">
          <button type="submit" class="btn-primary">Simpan Opname</button>
        </div>
      </form>
    </div>

    <div class="p-5 hidden" data-tab-panel="filter">
      <form method="GET" action="{{ route('accountingapp.stock-opnames.index') }}"
            class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div>
          <label class="form-label">Item</label>
          <select name="inventory_item_id" class="form-control">
            <option value="">Semua Item</option>
            @foreach($items as $item)
              <option value="{{ $item->id }}" @selected(($itemId ?? null) == $item->id)>
                {{ $item->name }} - {{ $item->categoryLabel() }}
              </option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="form-label">Dari Tanggal</label>
          <input type="date" name="date_from" value="{{ $dateFrom ?? '' }}"
                 class="form-control">
        </div>

        <div>
          <label class="form-label">Sampai Tanggal</label>
          <input type="date" name="date_to" value="{{ $dateTo ?? '' }}"
                 class="form-control">
        </div>

        <div class="md:col-span-5 flex justify-end">
          <button class="btn-secondary">Filter</button>
        </div>
      </form>

      <div class="table-card mt-6">
        <div class="table-card-head">Daftar Stock Opname</div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="text-left px-4 py-2">Tanggal</th>
                <th class="text-left px-4 py-2">Item</th>
                <th class="text-right px-4 py-2">Total Cost</th>
                <th class="text-left px-4 py-2">Catatan</th>
                <th class="text-left px-4 py-2">Aksi</th>
              </tr>
            </thead>
            <tbody>
              @forelse($opnames as $opname)
                <tr class="border-t">
                  <td class="px-4 py-2">{{ $opname->opname_date->format('d-m-Y') }}</td>
                  <td class="px-4 py-2">
                    {{ $opname->item->name ?? '-' }}
                    @if($opname->item)
                      <div class="text-xs text-slate-500">{{ $opname->item->categoryLabel() }}</div>
                    @endif
                  </td>
                  <td class="px-4 py-2 text-right">{{ number_format((float) $opname->total_value, 2, ',', '.') }}</td>
                  <td class="px-4 py-2">{{ $opname->notes ?: '-' }}</td>
                  <td class="px-4 py-2">
                    <form method="POST" action="{{ route('accountingapp.stock-opnames.destroy', $opname) }}"
                          onsubmit="return confirm('Hapus stock opname ini?')">
                      @csrf
                      @method('DELETE')
                      <button type="submit" class="btn-link-danger">Hapus</button>
                    </form>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="5" class="px-4 py-6 text-center text-gray-500">Belum ada stock opname.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        <div class="p-4">
          {{ $opnames->links() }}
        </div>
      </div>
    </div>
  </div>

  <script>
    (() => {
      const tabCards = document.querySelectorAll('.js-tab-card');

      tabCards.forEach((card) => {
        const defaultTab = card.dataset.activeTab || 'filter';
        const triggers = card.querySelectorAll('[data-tab-trigger]');
        const panels = card.querySelectorAll('[data-tab-panel]');

        const activateTab = (name) => {
          triggers.forEach((trigger) => {
            const isActive = trigger.dataset.tabTrigger === name;
            trigger.classList.toggle('bg-white', isActive);
            trigger.classList.toggle('text-gray-900', isActive);
            trigger.classList.toggle('shadow-sm', isActive);
            trigger.classList.toggle('text-gray-600', !isActive);
          });

          panels.forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.tabPanel !== name);
          });
        };

        triggers.forEach((trigger) => {
          trigger.addEventListener('click', () => activateTab(trigger.dataset.tabTrigger));
        });

        activateTab(defaultTab);
      });
    })();
  </script>
@endsection

