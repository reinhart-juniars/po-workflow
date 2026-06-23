@extends('layouts.accountingapp', ['title' => 'Edit Adjustment Neraca'])

@section('content')
    <h1 class="text-2xl font-bold mb-4">Edit Adjustment Neraca</h1>

    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
        Hanya role <strong>owner</strong> dan <strong>superadmin</strong> yang dapat mengubah adjustment neraca.
    </div>

    <div class="section-card">
        <form method="POST" action="{{ route('accountingapp.balance-sheet-adjustments.update', $balanceSheetAdjustment) }}"
            class="grid grid-cols-1 gap-3 md:grid-cols-5">
            @csrf
            @method('PUT')

            <div>
                <label class="form-label">Tanggal Adjustment</label>
                <input type="date" name="adjustment_date"
                    value="{{ old('adjustment_date', $balanceSheetAdjustment->adjustment_date?->toDateString()) }}"
                    class="form-control" required>
            </div>

            <div>
                <label class="form-label">Grup Neraca</label>
                <select name="group" class="form-control" required>
                    <option value="">Pilih Grup</option>
                    @foreach ($groupOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('group', $balanceSheetAdjustment->account_group) === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="form-label">Nama Pos</label>
                <input type="text" name="label" value="{{ old('label', $balanceSheetAdjustment->label) }}"
                    class="form-control" required>
            </div>

            <div>
                <label class="form-label">Nominal Selisih</label>
                <input type="number" name="amount" step="0.01"
                    value="{{ old('amount', $balanceSheetAdjustment->amount) }}" class="form-control" required>
                <div class="field-help">Boleh negatif untuk mengurangi saldo.</div>
            </div>

            <div class="md:col-span-5">
                <label class="form-label">Catatan</label>
                <textarea name="notes" rows="2" class="form-control">{{ old('notes', $balanceSheetAdjustment->notes) }}</textarea>
            </div>

            <div class="md:col-span-5 flex justify-end gap-3">
                <a href="{{ route('accountingapp.balance-sheet-adjustments.index') }}" class="btn-outline">Batal</a>
                <button type="submit" class="btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
@endsection
