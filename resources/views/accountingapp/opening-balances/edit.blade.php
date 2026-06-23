@extends('layouts.accountingapp', ['title' => 'Edit Saldo Awal'])

@section('content')
  @php
    $typeOptions = [
        'cash' => 'Tunai',
        'receivable' => 'Piutang',
        'payable' => 'Hutang',
    ];
  @endphp

  <h1 class="text-2xl font-bold mb-4">Edit Saldo Awal</h1>

  <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
    Hanya role <strong>owner</strong> dan <strong>superadmin</strong> yang dapat mengubah data saldo awal.
  </div>

  <div class="section-card">
    <form method="POST" action="{{ route('accountingapp.opening-balances.update', $openingBalance) }}"
          class="grid grid-cols-1 gap-3 md:grid-cols-5">
      @csrf
      @method('PUT')

      <div>
        <label class="form-label">Tanggal</label>
        <input type="date" name="balance_date"
               value="{{ old('balance_date', $openingBalance->balance_date?->toDateString()) }}"
               class="form-control" required>
      </div>

      <div>
        <label class="form-label">Tipe</label>
        <select name="type" id="opening-balance-type" class="form-control" required>
          <option value="">Pilih Tipe</option>
          @foreach($typeOptions as $value => $label)
            <option value="{{ $value }}" @selected(old('type', $openingBalance->type) === $value)>{{ $label }}</option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Supplier</label>
        <input type="text" name="supplier_name" id="opening-balance-supplier"
               value="{{ old('supplier_name', $openingBalance->supplier_name ?? $openingBalance->payable?->supplier_name) }}"
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
        <input type="number" name="amount" min="0" step="0.01"
               value="{{ old('amount', $openingBalance->amount) }}"
               class="form-control" required>
      </div>

      <div class="md:col-span-5">
        <label class="form-label">Keterangan</label>
        <textarea name="description" rows="2"
                  class="form-control">{{ old('description', $openingBalance->description) }}</textarea>
      </div>

      <div class="md:col-span-5 flex justify-end gap-3">
        <a href="{{ route('accountingapp.opening-balances.index') }}" class="btn-outline">
          Batal
        </a>
        <button type="submit" class="btn-primary">
          Simpan Perubahan
        </button>
      </div>
    </form>
  </div>

  <script>
    (function () {
      const typeSelect = document.getElementById('opening-balance-type');
      const referenceWrapper = document.getElementById('opening-balance-reference-wrapper');
      const referenceSelect = document.getElementById('opening-balance-reference');
      const referenceHelp = document.getElementById('opening-balance-reference-help');
      const supplierInput = document.getElementById('opening-balance-supplier');
      const supplierHelp = document.getElementById('opening-balance-supplier-help');

      if (!typeSelect || !referenceSelect || !referenceHelp || !referenceWrapper || !supplierInput || !supplierHelp) return;

      const initialReferenceId = @json(old('reference_id', $openingBalance->reference_id));
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
