@extends('layouts.accountingapp', ['title' => 'Saldo Awal'])

@section('content')
  @php
    $activeTab = 'list';
    $formFields = ['balance_date', 'type', 'reference_id', 'supplier_name', 'amount', 'description'];
    foreach ($formFields as $field) {
        if ($errors->has($field)) {
            $activeTab = 'form';
            break;
        }
    }

    $typeOptions = [
        'cash' => 'Tunai',
        'receivable' => 'Piutang',
        'payable' => 'Hutang',
    ];
  @endphp
  @php
    $canEditOpeningBalance = auth()->user()?->hasAnyRole(['owner', 'superadmin']);
  @endphp

  <h1 class="text-2xl font-bold mb-4">Saldo Awal</h1>

  <div class="tab-card-shell js-tab-card" data-active-tab="{{ $activeTab }}">
    <div class="panel-head">
      <div class="inline-flex items-center gap-1 rounded-lg bg-gray-100 p-1">
        <button
          type="button"
          data-tab-trigger="form"
          class="tab-trigger text-gray-600 hover:text-gray-800"
        >
          Tambah Saldo Awal
        </button>
        <button
          type="button"
          data-tab-trigger="list"
          class="tab-trigger text-gray-600 hover:text-gray-800"
        >
          Daftar Data
        </button>
      </div>
    </div>

    <div class="p-5" data-tab-panel="form">
      <form method="POST" action="{{ route('accountingapp.opening-balances.store') }}"
            class="grid grid-cols-1 md:grid-cols-5 gap-3">
        @csrf

        <div>
          <label class="form-label">Tanggal</label>
          <input type="date" name="balance_date" value="{{ old('balance_date', now()->toDateString()) }}"
                 class="form-control" required>
        </div>

        <div>
          <label class="form-label">Tipe</label>
          <select name="type" id="opening-balance-type" class="form-control" required>
            <option value="">Pilih Tipe</option>
            @foreach($typeOptions as $value => $label)
              <option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="form-label">Supplier</label>
          <input type="text" name="supplier_name" id="opening-balance-supplier" value="{{ old('supplier_name') }}"
                 class="form-control" placeholder="Wajib untuk tipe hutang">
          <div id="opening-balance-supplier-help" class="field-help"></div>
        </div>

        <div id="opening-balance-reference-wrapper">
          <label class="form-label">Referensi</label>
          <select name="reference_id" id="opening-balance-reference" class="form-control">
            <option value="">Tanpa Referensi</option>
          </select>
          <div id="opening-balance-reference-help" class="field-help"></div>
        </div>

        <div>
          <label class="form-label">Nominal</label>
          <input type="number" name="amount" min="0" step="0.01" value="{{ old('amount') }}"
                 class="form-control" required>
        </div>

        <div class="md:col-span-5">
          <label class="form-label">Keterangan</label>
          <textarea name="description" rows="2"
                    class="form-control">{{ old('description') }}</textarea>
        </div>

        <div class="md:col-span-5 flex justify-end">
          <button type="submit" class="btn-primary">
            Simpan Saldo Awal
          </button>
        </div>
      </form>
    </div>

    <div class="p-5 hidden" data-tab-panel="list">
      <div class="table-card mt-6">
        <div class="table-card-head">Daftar Saldo Awal</div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="text-left px-4 py-2">Tanggal</th>
                <th class="text-left px-4 py-2">Tipe</th>
                <th class="text-left px-4 py-2">Referensi</th>
                <th class="text-left px-4 py-2">Keterangan</th>
                <th class="text-right px-4 py-2">Nominal</th>
                <th class="text-left px-4 py-2">Input Oleh</th>
                <th class="text-left px-4 py-2">Update Terakhir</th>
                @if($canEditOpeningBalance)
                  <th class="text-left px-4 py-2">Aksi</th>
                @endif
              </tr>
            </thead>
            <tbody>
              @forelse($openingBalances as $openingBalance)
                @php
                  $typeLabel = $typeOptions[$openingBalance->type] ?? ucfirst($openingBalance->type);
                  $referenceLabel = '-';

                  if ($openingBalance->type === 'cash' && $openingBalance->reference_id) {
                      $referenceLabel = $openingBalance->cashAccount->name ?? '-';
                  } elseif ($openingBalance->type === 'receivable' && $openingBalance->reference_id) {
                      $referenceLabel = $openingBalance->customer->name ?? '-';
                  } elseif ($openingBalance->type === 'payable') {
                      $referenceLabel = $openingBalance->supplier_name ?? $openingBalance->payable?->supplier_name ?? '-';
                  }
                @endphp
                <tr class="border-t">
                  <td class="px-4 py-2">{{ $openingBalance->balance_date->format('d-m-Y') }}</td>
                  <td class="px-4 py-2">{{ $typeLabel }}</td>
                  <td class="px-4 py-2">{{ $referenceLabel }}</td>
                  <td class="px-4 py-2">{{ $openingBalance->description ?: '-' }}</td>
                  <td class="px-4 py-2 text-right">Rp {{ number_format($openingBalance->amount, 0, ',', '.') }}</td>
                  <td class="px-4 py-2">{{ $openingBalance->creator->name ?? '-' }}</td>
                  <td class="px-4 py-2">{{ $openingBalance->updater->name ?? '-' }}</td>
                  @if($canEditOpeningBalance)
                    <td class="px-4 py-2">
                      <a href="{{ route('accountingapp.opening-balances.edit', $openingBalance) }}"
                         class="font-medium text-indigo-600 hover:text-indigo-700">
                        Edit
                      </a>
                    </td>
                  @endif
                </tr>
              @empty
                <tr>
                  <td colspan="{{ $canEditOpeningBalance ? 8 : 7 }}" class="px-4 py-6 text-center text-gray-500">
                    Belum ada data saldo awal.
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        <div class="p-4">
          {{ $openingBalances->links() }}
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const tabCards = document.querySelectorAll('.js-tab-card');
      if (tabCards.length) {
        tabCards.forEach((card) => {
          const triggers = card.querySelectorAll('[data-tab-trigger]');
          const panels = card.querySelectorAll('[data-tab-panel]');
          const activeClasses = ['bg-white', 'text-indigo-700', 'shadow-sm'];
          const inactiveClasses = ['text-gray-600', 'hover:text-gray-800'];

          const setActive = (target) => {
            triggers.forEach((button) => {
              const isActive = button.dataset.tabTrigger === target;
              button.setAttribute('aria-selected', isActive ? 'true' : 'false');

              if (isActive) {
                button.classList.add(...activeClasses);
                button.classList.remove(...inactiveClasses);
              } else {
                button.classList.remove(...activeClasses);
                button.classList.add(...inactiveClasses);
              }
            });

            panels.forEach((panel) => {
              panel.classList.toggle('hidden', panel.dataset.tabPanel !== target);
            });
          };

          triggers.forEach((button) => {
            button.addEventListener('click', () => setActive(button.dataset.tabTrigger));
          });

          setActive(card.dataset.activeTab || (triggers[0] && triggers[0].dataset.tabTrigger));
        });
      }

      const typeSelect = document.getElementById('opening-balance-type');
      const referenceWrapper = document.getElementById('opening-balance-reference-wrapper');
      const referenceSelect = document.getElementById('opening-balance-reference');
      const referenceHelp = document.getElementById('opening-balance-reference-help');
      const supplierInput = document.getElementById('opening-balance-supplier');
      const supplierHelp = document.getElementById('opening-balance-supplier-help');

      if (!typeSelect || !referenceSelect || !referenceHelp || !referenceWrapper || !supplierInput || !supplierHelp) return;

      const initialReferenceId = @json(old('reference_id'));
      let hasRenderedReference = false;
      const options = {
        cash: @json($cashAccounts->map(fn ($cashAccount) => ['id' => $cashAccount->id, 'name' => $cashAccount->name])->values()),
        receivable: @json($customers->map(fn ($customer) => ['id' => $customer->id, 'name' => $customer->name])->values()),
        payable: [],
      };

      const helpText = {
        cash: 'Akun kas wajib dipilih untuk tipe tunai.',
        receivable: 'Customer wajib dipilih untuk tipe piutang.',
        payable: 'Supplier wajib diisi untuk tipe hutang.',
      };

      const renderReferenceOptions = () => {
        const type = typeSelect.value;
        const usesReference = type === 'cash' || type === 'receivable';
        const items = options[type] || [];
        const selectedValue = hasRenderedReference
          ? (referenceSelect.dataset.selected || '')
          : (referenceSelect.dataset.selected || initialReferenceId || '');

        referenceSelect.innerHTML = '<option value=\"\">Tanpa Referensi</option>';

        if (usesReference) {
          items.forEach((item) => {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.name;
            option.selected = String(selectedValue) === String(item.id);
            referenceSelect.appendChild(option);
          });
        }

        referenceWrapper.classList.toggle('hidden', !usesReference);
        referenceSelect.disabled = !usesReference;
        referenceHelp.textContent = usesReference ? (helpText[type] || '') : '';
        supplierInput.closest('div').classList.toggle('hidden', type !== 'payable');
        supplierInput.disabled = type !== 'payable';
        supplierHelp.textContent = type === 'payable' ? helpText.payable : '';
        referenceSelect.dataset.selected = referenceSelect.value;
        hasRenderedReference = true;
      };

      typeSelect.addEventListener('change', () => {
        referenceSelect.dataset.selected = '';
        renderReferenceOptions();
      });

      referenceSelect.addEventListener('change', () => {
        referenceSelect.dataset.selected = referenceSelect.value;
      });

      renderReferenceOptions();
    })();
  </script>
@endsection


