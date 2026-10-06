@extends('layouts.accountingapp', ['title' => 'Edit Adjustment Laba Rugi'])

@section('content')
    <h1 class="text-2xl font-bold mb-4">Edit Adjustment Laba Rugi</h1>

    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
        Hanya role <strong>owner</strong> dan <strong>superadmin</strong> yang dapat mengubah adjustment laba rugi.
        Tanggal tetap dibatasi ke bulan historis sebelum bulan berjalan.
    </div>

    <div class="section-card">
        <form method="POST" action="{{ route('accountingapp.profit-loss-adjustments.update', $profitLossAdjustment) }}"
            class="grid grid-cols-1 gap-3 md:grid-cols-5">
            @csrf
            @method('PUT')

            <div>
                <label class="form-label">Tanggal Adjustment</label>
                <input type="date" name="adjustment_date"
                    value="{{ old('adjustment_date', $profitLossAdjustment->adjustment_date?->toDateString()) }}"
                    max="{{ $maxAdjustmentDate }}" class="form-control" required>
                <div class="field-help">Maksimal tanggal {{ \Carbon\Carbon::parse($maxAdjustmentDate)->format('d-m-Y') }}.</div>
            </div>

            <div>
                <label class="form-label">Grup Laba Rugi</label>
                <select name="group" class="form-control" required data-pl-group-select>
                    <option value="">Pilih Grup</option>
                    @foreach ($groupOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('group', $profitLossAdjustment->statement_group) === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div data-pl-expense-category-wrapper style="display: none;">
                <label class="form-label">Kategori Beban</label>
                <select name="expense_category_id" class="form-control">
                    <option value="">Tidak terkait kategori</option>
                    @foreach ($expenseCategories as $category)
                        <option value="{{ $category->id }}" @selected((string) old('expense_category_id', $profitLossAdjustment->expense_category_id) === (string) $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
                <div class="field-help">Pilih kategori untuk merge ke baris kategori di laporan. Kosongkan untuk baris terpisah.</div>
            </div>

            <div class="md:col-span-2">
                <label class="form-label">Nama Pos</label>
                <input type="text" name="label" value="{{ old('label', $profitLossAdjustment->label) }}"
                    class="form-control" required>
            </div>

            <div>
                <label class="form-label">Nominal Selisih</label>
                <input type="number" name="amount" step="0.01"
                    value="{{ old('amount', $profitLossAdjustment->amount) }}" class="form-control" required>
                <div class="field-help">Boleh negatif untuk membalik efek grup.</div>
            </div>

            <div class="md:col-span-5">
                <label class="form-label">Catatan</label>
                <textarea name="notes" rows="2" class="form-control">{{ old('notes', $profitLossAdjustment->notes) }}</textarea>
            </div>

            <div class="md:col-span-5 flex justify-end gap-3">
                <a href="{{ route('accountingapp.profit-loss-adjustments.index') }}" class="btn-outline">Batal</a>
                <button type="submit" class="btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>

    <script>
        (() => {
            const groupSelect = document.querySelector('[data-pl-group-select]');
            const wrapper = document.querySelector('[data-pl-expense-category-wrapper]');
            if (!groupSelect || !wrapper) {
                return;
            }
            const toggle = () => {
                wrapper.style.display = groupSelect.value === 'operating_expense' ? '' : 'none';
                if (groupSelect.value !== 'operating_expense') {
                    const select = wrapper.querySelector('select');
                    if (select) {
                        select.value = '';
                    }
                }
            };
            groupSelect.addEventListener('change', toggle);
            toggle();
        })();
    </script>
@endsection
