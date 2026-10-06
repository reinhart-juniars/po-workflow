{{--
    Form rincian Barang Sisa per komponen (diketik bebas).
    Parameter: $action, $method ('POST'|'PUT'), $key (unik per form), $unitCost (HPP acuan per porsi),
    $portionQty, $maxPortion, $components (list {name, qty, unit, value}), $withDate, $notes.
--}}
@php
    $blankRows = max(2, 5 - count($components));
    $rows = array_merge($components, array_fill(0, $blankRows, ['name' => '', 'qty' => '', 'unit' => '', 'value' => '']));
    $columns = 'sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]';
@endphp
<form method="POST" action="{{ $action }}" class="mt-3 max-w-4xl space-y-3 js-breakdown-form" data-unit-cost="{{ $unitCost }}">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="grid gap-3 sm:grid-cols-3">
        <div>
            <label class="form-label" for="bd-portion-{{ $key }}">Porsi dirinci</label>
            <input id="bd-portion-{{ $key }}" type="number" name="portion_qty" step="0.01" min="0.01" @if ($maxPortion) max="{{ $maxPortion }}" @endif
                value="{{ $portionQty }}" class="form-control text-right tabular-nums js-bd-portion" required>
        </div>
        @if ($withDate)
            <div>
                <label class="form-label" for="bd-date-{{ $key }}">Tanggal</label>
                <input id="bd-date-{{ $key }}" type="date" name="broken_at" value="{{ now()->toDateString() }}"
                    max="{{ now()->toDateString() }}" class="form-control" required>
            </div>
        @endif
        <div>
            <label class="form-label" for="bd-notes-{{ $key }}">Catatan</label>
            <input id="bd-notes-{{ $key }}" type="text" name="notes" value="{{ $notes }}" maxlength="255"
                placeholder="opsional" class="form-control">
        </div>
    </div>

    <p class="text-xs text-slate-500">
        Komponen dan nilainya diketik sendiri. Acuan: HPP Rp {{ number_format((float) $unitCost, 0, ',', '.') }} per porsi.
        Total nilai komponen tidak boleh melebihi HPP porsi yang dirinci; sisanya dicatat sebagai waste. Baris kosong diabaikan.
    </p>

    <div class="space-y-2">
        <div class="hidden gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 sm:grid {{ $columns }}">
            <span>Komponen</span><span class="text-right">Jumlah</span><span>Satuan</span><span class="text-right">Nilai HPP (Rp)</span>
        </div>
        @foreach ($rows as $index => $row)
            <div class="grid grid-cols-3 gap-2 {{ $columns }}">
                <input type="text" name="components[{{ $index }}][name]" value="{{ $row['name'] }}" maxlength="100"
                    placeholder="mis. telur ceplok" aria-label="Nama komponen {{ $index + 1 }}" class="form-control col-span-3 sm:col-span-1">
                <input type="number" name="components[{{ $index }}][qty]" value="{{ $row['qty'] }}" step="0.01" min="0"
                    placeholder="Jumlah" aria-label="Jumlah komponen {{ $index + 1 }}" class="form-control text-right tabular-nums">
                <input type="text" name="components[{{ $index }}][unit]" value="{{ $row['unit'] }}" maxlength="30"
                    placeholder="gram, butir" aria-label="Satuan komponen {{ $index + 1 }}" class="form-control">
                <input type="number" name="components[{{ $index }}][value]" value="{{ $row['value'] }}" step="0.01" min="0"
                    placeholder="Nilai" aria-label="Nilai HPP komponen {{ $index + 1 }}" class="form-control text-right tabular-nums js-bd-value">
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm font-semibold text-slate-700">
            Total nilai komponen <span class="js-bd-total">Rp 0</span> dari HPP <span class="js-bd-hpp">Rp 0</span>
            <span class="js-bd-over hidden text-rose-600">· melebihi HPP</span>
        </p>
        <button type="submit" class="btn-primary">{{ $method === 'POST' ? 'Simpan Rincian' : 'Simpan Perubahan' }}</button>
    </div>
</form>
