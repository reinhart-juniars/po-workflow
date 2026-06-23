@extends('layouts.accountingapp', ['title' => 'Edit Pembelian Stok'])

@section('content')
  @php
    $selectedPaymentType = old('payment_type', $inventoryPurchase->payment_type);
    $selectedExpenseCategoryId = old('expense_category_id', $inventoryPurchase->cashOut?->expense_category_id);
    $selectedCashAccountId = old('cash_account_id', $inventoryPurchase->cashOut?->cash_account_id);
  @endphp

  <h1 class="text-2xl font-bold mb-4">Edit Pembelian Stok</h1>

  <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
    Perbaiki data pembelian stok di sini. Untuk tipe <strong>Kredit</strong>, kategori pengeluaran dan akun kas tidak dipakai.
  </div>

  <div class="section-card">
    <form method="POST" action="{{ route('accountingapp.inventory-purchases.update', $inventoryPurchase) }}"
          class="grid grid-cols-1 gap-3 md:grid-cols-5">
      @csrf
      @method('PUT')

      <div>
        <label class="form-label">Item</label>
        <select name="inventory_item_id" class="form-control" required>
          <option value="">Pilih Item</option>
          @foreach($items as $item)
            <option value="{{ $item->id }}" @selected((string) old('inventory_item_id', $inventoryPurchase->inventory_item_id) === (string) $item->id)>
              {{ $item->name }} ({{ $item->unit }}) - {{ $item->categoryLabel() }}
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Tanggal</label>
        <input type="date" name="transaction_date"
               value="{{ old('transaction_date', $inventoryPurchase->transaction_date->toDateString()) }}"
               class="form-control" required>
      </div>

      <div>
        <label class="form-label">Total Cost</label>
        <input type="number" name="total_cost" min="0" step="0.01"
               value="{{ old('total_cost', $inventoryPurchase->total_value) }}"
               class="form-control" required>
      </div>

      <div>
        <label class="form-label">Tipe Pembayaran</label>
        <select name="payment_type" id="payment_type" class="form-control" required>
          <option value="cash" @selected($selectedPaymentType === 'cash')>Tunai</option>
          <option value="payable" @selected($selectedPaymentType === 'payable')>Kredit</option>
        </select>
      </div>

      <div id="expense_category_wrapper">
        <label class="form-label">Kategori Pengeluaran</label>
        <select name="expense_category_id" class="form-control">
          <option value="">Pilih kategori inventory purchase</option>
          @foreach($inventoryCategories as $category)
            <option value="{{ $category->id }}" @selected((string) $selectedExpenseCategoryId === (string) $category->id)>
              {{ $category->name }}
            </option>
          @endforeach
        </select>
      </div>

      <div id="cash_account_wrapper">
        <label class="form-label">Akun Kas</label>
        <select name="cash_account_id" class="form-control">
          <option value="">Pilih akun kas</option>
          @foreach($cashAccounts as $cashAccount)
            <option value="{{ $cashAccount->id }}" @selected((string) $selectedCashAccountId === (string) $cashAccount->id)>
              {{ $cashAccount->name }}
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Supplier</label>
        <input type="text" name="supplier_name"
               value="{{ old('supplier_name', $inventoryPurchase->supplier_name) }}"
               class="form-control">
      </div>

      <div id="due_date_wrapper">
        <label class="form-label">Jatuh Tempo</label>
        <input type="date" name="due_date" id="due_date"
               value="{{ old('due_date', $inventoryPurchase->payable?->due_date?->toDateString()) }}"
               class="form-control">
      </div>

      <div class="md:col-span-5">
        <label class="form-label">Catatan</label>
        <textarea name="notes" rows="2" class="form-control">{{ old('notes', $inventoryPurchase->notes) }}</textarea>
      </div>

      <div class="md:col-span-5 flex justify-end gap-3">
        <a href="{{ route('accountingapp.inventory-purchases.index') }}" class="btn-outline">Batal</a>
        <button type="submit" class="btn-primary">Simpan Perubahan</button>
      </div>
    </form>
  </div>

  <script>
    (() => {
      const paymentType = document.getElementById('payment_type');
      const dueDateWrapper = document.getElementById('due_date_wrapper');
      const dueDateInput = document.getElementById('due_date');
      const expenseCategoryWrapper = document.getElementById('expense_category_wrapper');
      const cashAccountWrapper = document.getElementById('cash_account_wrapper');
      const expenseCategorySelect = expenseCategoryWrapper?.querySelector('select');
      const cashAccountSelect = cashAccountWrapper?.querySelector('select');

      if (!paymentType || !dueDateWrapper || !dueDateInput || !expenseCategoryWrapper || !cashAccountWrapper) {
        return;
      }

      const syncPaymentType = () => {
        const isCredit = paymentType.value === 'payable';

        dueDateWrapper.classList.toggle('hidden', !isCredit);
        dueDateInput.disabled = !isCredit;

        expenseCategoryWrapper.classList.toggle('hidden', isCredit);
        cashAccountWrapper.classList.toggle('hidden', isCredit);

        if (expenseCategorySelect) {
          expenseCategorySelect.disabled = isCredit;
        }

        if (cashAccountSelect) {
          cashAccountSelect.disabled = isCredit;
        }
      };

      paymentType.addEventListener('change', syncPaymentType);
      syncPaymentType();
    })();
  </script>
@endsection
