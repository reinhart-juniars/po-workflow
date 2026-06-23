@extends('layouts.accountingapp', ['title' => 'Pengeluaran'])

@section('content')
  @php
    $requestedTab = request('tab');
    $activeTab = in_array($requestedTab, ['form', 'payable-settlement', 'filter'], true) ? $requestedTab : 'filter';
    $formFields = ['expense_date', 'expense_category_id', 'payment_type', 'cash_account_id', 'payable_id', 'amount', 'description', 'adjustment_note', 'inventory_item_id', 'inventory_unit_cost', 'supplier_name', 'due_date'];
    $oldExpenseFlow = old('expense_flow', 'expense');
    $selectedSettlementPayableId = $oldExpenseFlow === 'payable_settlement'
        ? old('payable_id')
        : request('payable_id');
    if ($errors->any()) {
        $activeTab = $oldExpenseFlow === 'payable_settlement' ? 'payable-settlement' : 'form';
    } else {
        foreach ($formFields as $field) {
            if ($errors->has($field)) {
                $activeTab = 'form';
                break;
            }
        }
    }

    $categoryModes = $categories
        ->mapWithKeys(fn ($category) => [(string) $category->id => $category->expense_mode])
        ->all();
  @endphp

  <h1 class="text-2xl font-bold mb-4">Pengeluaran</h1>

  <div class="mb-4 {{ $rangePeriodStatus['has_closed_periods'] ? 'notice-soft-amber' : 'notice-soft-emerald' }}">
    <strong>Status periode:</strong> {{ $rangePeriodStatus['message'] }}
  </div>

  <div class="tab-card-shell js-tab-card" data-active-tab="{{ $activeTab }}">
    <div class="panel-head">
      <div class="inline-flex items-center gap-1 rounded-lg bg-gray-100 p-1">
        <button
          type="button"
          data-tab-trigger="form"
          class="tab-trigger text-gray-600 hover:text-gray-800"
        >
          Tambah Pengeluaran
        </button>
        <button
          type="button"
          data-tab-trigger="payable-settlement"
          class="tab-trigger text-gray-600 hover:text-gray-800"
        >
          Pembayaran Kredit
        </button>
        <button
          type="button"
          data-tab-trigger="filter"
          class="tab-trigger text-gray-600 hover:text-gray-800"
        >
          Filter Data
        </button>
      </div>
    </div>

    <div class="p-5" data-tab-panel="form">
      <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" id="expense-flow-hint">
        Gunakan form ini untuk pengeluaran biasa dan pembelian stok.
      </div>

      <form method="POST" action="{{ route('accountingapp.expenses.store') }}"
            class="grid grid-cols-1 md:grid-cols-5 gap-3">
        @csrf
        <input type="hidden" name="expense_flow" value="expense">

        <div>
          <label class="form-label">Tanggal</label>
          <input type="date" name="expense_date" value="{{ old('expense_date', now()->toDateString()) }}"
                 class="form-control" required>
        </div>

        <div>
          <label class="form-label">Kategori</label>
          <select name="expense_category_id" id="expense-category" class="form-control" required>
            <option value="">Pilih Kategori</option>
            @foreach($expenseEntryCategories as $category)
              <option value="{{ $category->id }}" @selected(old('expense_category_id') == $category->id)>
                {{ $category->name }}
              </option>
            @endforeach
          </select>
        </div>

        <div id="expense-payment-type-wrapper">
          <label class="form-label">Tipe Pembayaran</label>
          <select name="payment_type" id="expense-payment-type" class="form-control">
            <option value="cash" @selected(old('payment_type', 'cash') === 'cash')>Tunai</option>
            <option value="payable" @selected(old('payment_type') === 'payable')>Kredit</option>
          </select>
        </div>

        <div id="expense-cash-account-wrapper">
          <label class="form-label">Akun Kas</label>
          <select name="cash_account_id" id="expense-cash-account" class="form-control">
            <option value="">Pilih Akun Kas</option>
            @foreach($cashAccounts as $cashAccount)
              <option value="{{ $cashAccount->id }}" @selected(old('cash_account_id') == $cashAccount->id)>
                {{ $cashAccount->name }} ({{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
              </option>
            @endforeach
          </select>
        </div>

        <div id="expense-amount-wrapper">
          <label class="form-label">Nominal</label>
          <input type="number" name="amount" id="expense-amount" min="1" step="0.01" value="{{ old('amount') }}"
                 class="form-control">
        </div>

        <div id="expense-inventory-item-wrapper">
          <label class="form-label">Item Inventory</label>
          <select name="inventory_item_id" id="expense-inventory-item" class="form-control">
            <option value="">Pilih Item Inventory</option>
            @foreach($inventoryItems as $inventoryItem)
              <option value="{{ $inventoryItem->id }}" @selected(old('inventory_item_id') == $inventoryItem->id)>
                {{ $inventoryItem->name }} ({{ $inventoryItem->unit }}) - {{ $inventoryItem->categoryLabel() }}
              </option>
            @endforeach
          </select>
        </div>

        <div id="expense-inventory-unit-cost-wrapper">
          <label class="form-label">Total Cost</label>
          <input type="number" name="inventory_unit_cost" id="expense-inventory-unit-cost" min="0" step="0.01" value="{{ old('inventory_unit_cost') }}"
                 class="form-control">
        </div>

        <div id="expense-due-date-wrapper">
          <label class="form-label">Jatuh Tempo</label>
          <input type="date" name="due_date" id="expense-due-date" value="{{ old('due_date') }}"
                 class="form-control">
        </div>

        <div class="md:col-span-2" id="expense-supplier-wrapper">
          <label class="form-label">Supplier</label>
          <input type="text" name="supplier_name" id="expense-supplier" value="{{ old('supplier_name') }}"
                 class="form-control"
                 placeholder="Wajib untuk pembelian stok kredit">
        </div>

        <div class="md:col-span-5">
          <label class="form-label">Keterangan</label>
          <textarea name="description" rows="2"
                    class="form-control">{{ old('description') }}</textarea>
        </div>

        <div class="md:col-span-5">
          <label class="form-label">Catatan Koreksi</label>
          <textarea name="adjustment_note" rows="2"
                    class="form-control"
                    placeholder="Wajib diisi jika tanggal masuk ke periode yang sudah ditutup.">{{ old('adjustment_note') }}</textarea>
        </div>

        <div class="md:col-span-5 flex justify-end">
          <button type="submit" class="btn-primary">
            Simpan Pengeluaran
          </button>
        </div>
      </form>
    </div>

    <div class="p-5 hidden" data-tab-panel="payable-settlement">
      <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Gunakan tab ini khusus untuk pembayaran kredit atau pelunasan hutang supplier. Kategori akan ditentukan otomatis oleh sistem.
      </div>

      <form method="POST" action="{{ route('accountingapp.expenses.store') }}"
            class="grid grid-cols-1 md:grid-cols-5 gap-3">
        @csrf
        <input type="hidden" name="expense_flow" value="payable_settlement">

        <div>
          <label class="form-label">Tanggal</label>
          <input type="date" name="expense_date" value="{{ old('expense_flow') === 'payable_settlement' ? old('expense_date', now()->toDateString()) : now()->toDateString() }}"
                 class="form-control" required>
        </div>

        <div>
          <label class="form-label">Akun Kas</label>
          <select name="cash_account_id" class="form-control" required>
            <option value="">Pilih Akun Kas</option>
            @foreach($cashAccounts as $cashAccount)
              <option value="{{ $cashAccount->id }}" @selected(old('expense_flow') === 'payable_settlement' && old('cash_account_id') == $cashAccount->id)>
                {{ $cashAccount->name }} ({{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
              </option>
            @endforeach
          </select>
        </div>

        <div class="md:col-span-2">
          <label class="form-label">Pilih Hutang</label>
          <select name="payable_id" id="settlement-payable" class="form-control" required>
            <option value="">Pilih Hutang Supplier</option>
            @foreach($openPayables as $payable)
              <option
                value="{{ $payable->id }}"
                data-amount="{{ (float) $payable->amount }}"
                data-supplier="{{ $payable->supplier_name }}"
                @selected((string) $selectedSettlementPayableId === (string) $payable->id)
              >
                {{ $payable->supplier_name }} - Rp {{ number_format((float) $payable->amount, 0, ',', '.') }}
              </option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="form-label">Nominal Pelunasan</label>
          <input type="text" id="settlement-amount-preview" class="form-control bg-gray-50" value="Otomatis mengikuti nominal hutang" readonly>
        </div>

        <div class="md:col-span-5 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
          <div><strong>Supplier:</strong> <span id="settlement-supplier-preview">Belum dipilih</span></div>
          <div class="mt-1"><strong>Catatan:</strong> Setelah disimpan, hutang terpilih akan ditandai lunas.</div>
        </div>

        <div class="md:col-span-5">
          <label class="form-label">Keterangan</label>
          <textarea name="description" rows="2"
                    class="form-control"
                    placeholder="Contoh: Pelunasan invoice supplier bulan ini">{{ old('expense_flow') === 'payable_settlement' ? old('description') : '' }}</textarea>
        </div>

        <div class="md:col-span-5">
          <label class="form-label">Catatan Koreksi</label>
          <textarea name="adjustment_note" rows="2"
                    class="form-control"
                    placeholder="Wajib diisi jika tanggal masuk ke periode yang sudah ditutup.">{{ old('expense_flow') === 'payable_settlement' ? old('adjustment_note') : '' }}</textarea>
        </div>

        <div class="md:col-span-5 flex justify-end">
          <button type="submit" class="btn-primary">
            Simpan Pembayaran Kredit
          </button>
        </div>
      </form>
    </div>

    <div class="p-5 hidden" data-tab-panel="filter">
      <form method="GET" action="{{ route('accountingapp.expenses.index') }}"
            class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div class="md:col-span-5 flex flex-wrap gap-2">
          <button type="button" class="chip-filter js-date-preset" data-form-scope="expenses-filter" data-preset="this_month">Bulan Ini</button>
          <button type="button" class="chip-filter js-date-preset" data-form-scope="expenses-filter" data-preset="last_month">Bulan Lalu</button>
          <button type="button" class="chip-filter js-date-preset" data-form-scope="expenses-filter" data-preset="this_year">Tahun Berjalan</button>
        </div>

        <div>
          <label class="form-label">Dari Tanggal</label>
          <input type="date" name="date_from" value="{{ $dateFrom->toDateString() }}"
                 data-form-scope="expenses-filter" data-role="date-from"
                 class="form-control">
        </div>

        <div>
          <label class="form-label">Sampai Tanggal</label>
          <input type="date" name="date_to" value="{{ $dateTo->toDateString() }}"
                 data-form-scope="expenses-filter" data-role="date-to"
                 class="form-control">
        </div>

        <div>
          <label class="form-label">Kategori</label>
          <select name="expense_category_id" class="form-control">
            <option value="">Semua Kategori</option>
            @foreach($categories as $category)
              <option value="{{ $category->id }}" @selected(($categoryId ?? null) == $category->id)>
                {{ $category->name }}
              </option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="form-label">Akun Kas</label>
          <select name="cash_account_id" class="form-control">
            <option value="">Semua Akun Kas</option>
            @foreach($cashAccounts as $cashAccount)
              <option value="{{ $cashAccount->id }}" @selected(($cashAccountId ?? null) == $cashAccount->id)>
                {{ $cashAccount->name }}
              </option>
            @endforeach
          </select>
        </div>

        <div class="flex items-end">
          <button class="btn-secondary w-full">Filter</button>
        </div>
      </form>
      <div class="table-card mt-6">
        <div class="table-card-head">Daftar Pengeluaran</div>

        <div>
          <table class="w-full text-sm table-fixed">
            <colgroup>
              <col style="width: 9rem">
              <col>
              <col>
              <col style="width: 8.5rem">
              <col style="width: 8rem">
            </colgroup>
            <thead class="bg-gray-50">
              <tr>
                <th class="text-left px-3 py-2">Tanggal</th>
                <th class="text-left px-3 py-2">Kategori / Akun</th>
                <th class="text-left px-3 py-2">Keterangan</th>
                <th class="text-right px-3 py-2">Nominal</th>
                <th class="text-left px-3 py-2">Aksi</th>
              </tr>
            </thead>
            <tbody>
              @forelse($expenses as $expense)
                <tr class="border-t align-top">
                  <td class="px-3 py-2">
                    <div class="font-medium text-slate-900 whitespace-nowrap">{{ $expense->expense_date->format('d-m-Y') }}</div>
                    <div class="mt-1 text-xs text-slate-500 truncate" title="{{ $expense->creator->name ?? '-' }}">
                      Input: {{ $expense->creator->name ?? '-' }}
                    </div>
                    @if($expense->updater && $expense->updated_by !== $expense->created_by)
                      <div class="text-xs text-slate-500 truncate" title="{{ $expense->updater->name }}">
                        Update: {{ $expense->updater->name }}
                      </div>
                    @endif
                  </td>
                  <td class="px-3 py-2">
                    <div class="font-medium text-slate-900 break-words">{{ $expense->category->name ?? '-' }}</div>
                    <div class="text-xs text-slate-500 break-words">{{ $expense->cashAccount->name ?? '-' }}</div>
                  </td>
                  <td class="px-3 py-2">
                    @if($expense->inventoryPurchase)
                      <div class="font-medium text-slate-900 break-words">
                        {{ $expense->inventoryPurchase->item->name ?? 'Item inventory' }}
                        <span class="text-xs font-normal text-slate-500">({{ $expense->inventoryPurchase->item?->categoryLabel() ?? '-' }})</span>
                      </div>
                    @endif
                    <div class="text-slate-700 break-words">{{ $expense->description ?: '-' }}</div>
                    @if($expense->is_adjustment || $expense->period_closed)
                      <div class="mt-1 flex flex-wrap gap-1">
                        @if($expense->is_adjustment)
                          <span class="badge-soft-amber">Koreksi</span>
                        @endif
                        @if($expense->period_closed)
                          <span class="badge-soft-slate">Periode Tertutup</span>
                        @endif
                      </div>
                    @endif
                  </td>
                  <td class="px-3 py-2 text-right whitespace-nowrap font-medium text-slate-900">
                    Rp {{ number_format($expense->amount, 0, ',', '.') }}
                  </td>
                  <td class="px-3 py-2 text-sm whitespace-nowrap">
                    <a href="{{ route('accountingapp.expenses.edit', $expense->id) }}"
                       class="font-semibold text-brand-600 hover:text-brand-700">Edit</a>
                    <span class="mx-1 text-slate-300">·</span>
                    <form method="POST" action="{{ route('accountingapp.expenses.destroy', $expense->id) }}"
                          class="inline"
                          onsubmit="return confirm('Hapus data pengeluaran ini?')">
                      @csrf
                      @method('DELETE')
                      <button type="submit"
                              class="font-semibold text-rose-600 hover:text-rose-700">Hapus</button>
                    </form>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                    Belum ada data pengeluaran.
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        <div class="p-4">
          {{ $expenses->links() }}
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const tabCards = document.querySelectorAll('.js-tab-card');
      if (!tabCards.length) return;

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

      const categoryModes = @json($categoryModes);
      const categorySelect = document.getElementById('expense-category');
      const paymentTypeSelect = document.getElementById('expense-payment-type');
      const flowHint = document.getElementById('expense-flow-hint');
      const wrappers = {
        paymentType: document.getElementById('expense-payment-type-wrapper'),
        cashAccount: document.getElementById('expense-cash-account-wrapper'),
        amount: document.getElementById('expense-amount-wrapper'),
        inventoryItem: document.getElementById('expense-inventory-item-wrapper'),
        inventoryUnitCost: document.getElementById('expense-inventory-unit-cost-wrapper'),
        dueDate: document.getElementById('expense-due-date-wrapper'),
        supplier: document.getElementById('expense-supplier-wrapper'),
      };
      const fields = {
        paymentType: paymentTypeSelect,
        cashAccount: document.getElementById('expense-cash-account'),
        amount: document.getElementById('expense-amount'),
        inventoryItem: document.getElementById('expense-inventory-item'),
        inventoryUnitCost: document.getElementById('expense-inventory-unit-cost'),
        dueDate: document.getElementById('expense-due-date'),
        supplier: document.getElementById('expense-supplier'),
      };

      const setFieldVisibility = (wrapper, field, visible) => {
        if (!wrapper || !field) return;

        wrapper.classList.toggle('hidden', !visible);
        field.disabled = !visible;
      };

      const syncExpenseFlow = () => {
        if (!categorySelect || !paymentTypeSelect) return;

        const selectedMode = categoryModes[categorySelect.value] || null;
        const isInventoryPurchase = selectedMode === 'inventory_purchase';
        const isInventoryPayable = isInventoryPurchase && paymentTypeSelect.value === 'payable';

        setFieldVisibility(wrappers.paymentType, fields.paymentType, isInventoryPurchase);
        setFieldVisibility(wrappers.inventoryItem, fields.inventoryItem, isInventoryPurchase);
        setFieldVisibility(wrappers.inventoryUnitCost, fields.inventoryUnitCost, isInventoryPurchase);
        setFieldVisibility(wrappers.supplier, fields.supplier, isInventoryPurchase);
        setFieldVisibility(wrappers.dueDate, fields.dueDate, isInventoryPayable);

        setFieldVisibility(wrappers.amount, fields.amount, !isInventoryPurchase);
        setFieldVisibility(wrappers.cashAccount, fields.cashAccount, !isInventoryPayable);

          if (flowHint) {
            if (!isInventoryPurchase) {
              flowHint.textContent = 'Gunakan form ini untuk pengeluaran biasa.';
            } else if (isInventoryPayable) {
              flowHint.textContent = 'Pembelian stok kredit akan tercatat di Monitoring Pembelian Stok dan membuat hutang baru.';
            } else {
              flowHint.textContent = 'Pembelian stok tunai akan tercatat sebagai pengeluaran dan otomatis masuk ke Monitoring Pembelian Stok.';
            }
          }
      };

      categorySelect?.addEventListener('change', syncExpenseFlow);
      paymentTypeSelect?.addEventListener('change', syncExpenseFlow);
      syncExpenseFlow();

      const applyPreset = (scope, preset) => {
        const fromInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-from"]`);
        const toInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-to"]`);

        if (!fromInput || !toInput) return;

        const now = new Date();
        const pad = (value) => String(value).padStart(2, '0');
        const format = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

        let fromDate = new Date(now.getFullYear(), now.getMonth(), 1);
        let toDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);

        if (preset === 'last_month') {
          fromDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
          toDate = new Date(now.getFullYear(), now.getMonth(), 0);
        } else if (preset === 'this_year') {
          fromDate = new Date(now.getFullYear(), 0, 1);
          toDate = new Date(now.getFullYear(), 11, 31);
        }

        fromInput.value = format(fromDate);
        toInput.value = format(toDate);
      };

      document.querySelectorAll('.js-date-preset').forEach((button) => {
        button.addEventListener('click', () => applyPreset(button.dataset.formScope, button.dataset.preset));
      });

      const settlementPayable = document.getElementById('settlement-payable');
      const settlementAmountPreview = document.getElementById('settlement-amount-preview');
      const settlementSupplierPreview = document.getElementById('settlement-supplier-preview');

      const formatCurrency = (amount) => new Intl.NumberFormat('id-ID').format(Number(amount || 0));
      const syncSettlementPreview = () => {
        if (!settlementPayable || !settlementAmountPreview || !settlementSupplierPreview) return;

        const selectedOption = settlementPayable.options[settlementPayable.selectedIndex];
        const amount = selectedOption?.dataset.amount;
        const supplier = selectedOption?.dataset.supplier;

        settlementAmountPreview.value = amount ? `Rp ${formatCurrency(amount)}` : 'Otomatis mengikuti nominal hutang';
        settlementSupplierPreview.textContent = supplier || 'Belum dipilih';
      };

      settlementPayable?.addEventListener('change', syncSettlementPreview);
      syncSettlementPreview();
    })();
  </script>
@endsection



