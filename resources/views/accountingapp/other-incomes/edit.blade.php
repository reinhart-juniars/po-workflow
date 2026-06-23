@extends('layouts.accountingapp', ['title' => 'Edit Pemasukan Lain'])

@section('content')
  <h1 class="text-2xl font-bold mb-4">Edit Pemasukan Lain</h1>

  <div class="mb-4 rounded-lg border px-4 py-3 text-sm {{ $isClosedPeriod ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
    <strong>Status periode transaksi:</strong>
    {{ $isClosedPeriod ? "Periode {$closedPeriodLabel} sudah ditutup. Koreksi hanya dapat dilakukan owner dengan catatan koreksi." : "Periode {$closedPeriodLabel} masih aktif." }}
  </div>

  <div class="section-card">
    <form method="POST" action="{{ route('accountingapp.other-incomes.update', $otherIncome->id) }}"
          class="grid grid-cols-1 md:grid-cols-4 gap-3">
      @csrf
      @method('PUT')

      <div>
        <label class="form-label">Tanggal</label>
        <input type="date" name="income_date"
               value="{{ old('income_date', $otherIncome->income_date->toDateString()) }}"
               class="form-control" required>
      </div>

      <div>
        <label class="form-label">Kategori Pemasukan</label>
        <select name="income_category_id" class="form-control" required>
          <option value="">Pilih Kategori</option>
          @foreach ($incomeCategories as $incomeCategory)
            <option value="{{ $incomeCategory->id }}" @selected((string) old('income_category_id', $otherIncome->income_category_id) === (string) $incomeCategory->id)>
              {{ $incomeCategory->name }}
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Cash Account</label>
        <select name="cash_account_id" class="form-control" required>
          <option value="">Pilih Cash Account</option>
          @foreach ($cashAccounts as $cashAccount)
            <option value="{{ $cashAccount->id }}" @selected((string) old('cash_account_id', $otherIncome->cash_account_id) === (string) $cashAccount->id)>
              {{ $cashAccount->name }} ({{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Nominal</label>
        <input type="number" name="amount" min="1" step="0.01"
               value="{{ old('amount', $otherIncome->amount) }}"
               class="form-control" required>
      </div>

      <div class="md:col-span-4">
        <label class="form-label">Keterangan</label>
        <textarea name="description" rows="2"
                  class="form-control">{{ old('description', $otherIncome->description) }}</textarea>
      </div>

      <div class="md:col-span-4">
        <label class="form-label">Catatan Koreksi</label>
        <textarea name="adjustment_note" rows="2"
                  class="form-control"
                  placeholder="Wajib diisi jika tanggal masuk ke periode yang sudah ditutup.">{{ old('adjustment_note', $otherIncome->adjustment_note) }}</textarea>
      </div>

      <div class="md:col-span-4 flex justify-end gap-3">
        <a href="{{ route('accountingapp.other-incomes.index') }}"
           class="btn-outline">
          Batal
        </a>
        <button type="submit" class="btn-primary">
          Simpan Perubahan
        </button>
      </div>
    </form>
  </div>
@endsection

