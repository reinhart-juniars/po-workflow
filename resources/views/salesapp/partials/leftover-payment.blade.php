{{--
    Cara bayar Penjualan Barang Sisa.
    - "po": ikut PO customer pembeli di DO Sales Actual tujuan (tunai/piutang sesuai PO).
    - "cash:{id}": tunai langsung ke akun kas itu -- untuk customer yang hari itu
      tidak punya DO (mis. lapak). Piutang hanya lewat PO, karena piutang dicatat per PO.
    Variabel: $id (id elemen), $cashAccounts, $selected (opsional, bawaan "po"),
    $hint (opsional, tampilkan petunjuk; bawaan true).
--}}
@php
    $selected = $selected ?? 'po';
@endphp
<label class="form-label" for="{{ $id }}">Cara bayar</label>
<select id="{{ $id }}" name="payment" class="form-control" required>
    <option value="po" @selected($selected === 'po')>Ikuti PO customer (DO hari ini)</option>
    @foreach ($cashAccounts as $cashAccount)
        <option value="cash:{{ $cashAccount->id }}" @selected($selected === 'cash:' . $cashAccount->id)>Tunai · {{ $cashAccount->name }}</option>
    @endforeach
</select>
@if ($hint ?? true)
    <p class="mt-1 text-[11px] text-slate-500">Customer tanpa DO hari ini: pilih Tunai dan akun kasnya.</p>
@endif
