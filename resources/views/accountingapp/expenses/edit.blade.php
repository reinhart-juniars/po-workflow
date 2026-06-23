@extends('layouts.accountingapp', ['title' => 'Edit Pengeluaran'])

@section('content')
  <h1 class="text-2xl font-bold mb-4">Edit Pengeluaran</h1>

  <div class="mb-4 rounded-lg border px-4 py-3 text-sm {{ $isClosedPeriod ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
    <strong>Status periode transaksi:</strong>
    {{ $isClosedPeriod ? "Periode {$closedPeriodLabel} sudah ditutup. Koreksi hanya dapat dilakukan owner dengan catatan koreksi." : "Periode {$closedPeriodLabel} masih aktif." }}
  </div>

  <div class="section-card">
    <form method="POST" action="{{ route('accountingapp.expenses.update', $cashOut->id) }}"
          class="grid grid-cols-1 md:grid-cols-2 gap-4">
      @csrf
      @method('PUT')

      <div>
        <label class="form-label">Tanggal</label>
        <input type="date" name="expense_date"
               value="{{ old('expense_date', $cashOut->expense_date->toDateString()) }}"
               class="form-control" required>
      </div>

      <div>
        <label class="form-label">Nominal</label>
        <input type="number" name="amount" min="1" step="0.01"
               value="{{ old('amount', $cashOut->amount) }}"
               class="form-control">
      </div>

      <div>
        <label class="form-label">Item Inventory</label>
        <select name="inventory_item_id" class="form-control">
          <option value="">Bukan pembelian stok</option>
          @foreach($inventoryItems as $inventoryItem)
            <option value="{{ $inventoryItem->id }}"
              @selected(old('inventory_item_id', $cashOut->inventoryPurchase?->inventory_item_id) == $inventoryItem->id)>
              {{ $inventoryItem->name }} ({{ $inventoryItem->unit }}) - {{ $inventoryItem->categoryLabel() }}
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Kategori</label>
        <select name="expense_category_id" class="form-control" required>
          @foreach($categories as $category)
            <option value="{{ $category->id }}"
              @selected(old('expense_category_id', $cashOut->expense_category_id) == $category->id)>
              {{ $category->name }}
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Akun Kas</label>
        <select name="cash_account_id" class="form-control" required>
          @foreach($cashAccounts as $cashAccount)
            <option value="{{ $cashAccount->id }}"
              @selected(old('cash_account_id', $cashOut->cash_account_id) == $cashAccount->id)>
              {{ $cashAccount->name }} ({{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Bayar Hutang</label>
        <select name="payable_id" class="form-control">
          <option value="">Bukan pembayaran hutang</option>
          @foreach($openPayables as $payable)
            <option value="{{ $payable->id }}"
              @selected(old('payable_id', $cashOut->payable_id) == $payable->id)>
              {{ $payable->supplier_name }} - Rp {{ number_format((float) $payable->amount, 0, ',', '.') }}
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Total Cost</label>
        <input type="number" name="inventory_unit_cost" min="0" step="0.01"
               value="{{ old('inventory_unit_cost', $cashOut->inventoryPurchase?->total_value) }}"
               class="form-control">
      </div>

      <div class="md:col-span-2">
        <label class="form-label">Supplier</label>
        <input type="text" name="supplier_name"
               value="{{ old('supplier_name', $cashOut->inventoryPurchase?->supplier_name) }}"
               class="form-control"
               placeholder="Opsional, dipakai untuk pembelian stok">
      </div>

      <div class="md:col-span-2">
        <label class="form-label">Keterangan</label>
        <textarea name="description" rows="3"
                  class="form-control">{{ old('description', $cashOut->description) }}</textarea>
      </div>

      <div class="md:col-span-2">
        <label class="form-label">Catatan Koreksi</label>
        <textarea name="adjustment_note" rows="3"
                  class="form-control"
                  placeholder="Wajib diisi jika tanggal masuk ke periode yang sudah ditutup.">{{ old('adjustment_note', $cashOut->adjustment_note) }}</textarea>
      </div>

      <div class="md:col-span-2 flex justify-end gap-2">
        <a href="{{ route('accountingapp.expenses.index') }}"
           class="btn-outline">
          Batal
        </a>
        <button type="submit" class="btn-primary">
          Update
        </button>
      </div>
    </form>
  </div>
@endsection

