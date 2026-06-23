@extends('layouts.adminapp', ['title' => 'SPK'])

@section('content')
@php use App\Support\UiLabel; @endphp
<section class="dashboard-hero">
  <h1 class="dashboard-hero-title">Setup SPK Produksi</h1>
  <p class="dashboard-hero-subtitle">
    Pilih PO berstatus draft, tentukan jadwal produksi, lalu teruskan ke proses SPK.
  </p>
</section>

@if ($errors->any())
  <div class="flash-error mt-4">
    <b>Terjadi kesalahan:</b>
    <ul class="list-disc pl-5">
      @foreach ($errors->all() as $e)
        <li>{{ $e }}</li>
      @endforeach
    </ul>
  </div>
@endif

<form action="{{ route('adminapp.spk.store') }}" method="POST" class="mt-4 space-y-5">
  @csrf

  <section class="table-shell">
    <div class="table-head flex items-center justify-between gap-2">
      <span>Pilih PO Draft</span>
      <span class="text-xs font-semibold text-slate-500">Terpilih: <span id="selectedPoCount">0</span></span>
    </div>
    <div class="data-table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Pilih</th>
            <th>PO Number</th>
            <th>Customer</th>
            <th>Kontak</th>
            <th>Tanggal Kirim</th>
            <th>Jam Terima</th>
            <th>Area</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($poDraftToday as $row)
            <tr>
              <td>
                <input type="checkbox" name="po_ids[]" value="{{ $row->id }}" class="po-check h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500/30">
              </td>
              <td class="font-mono text-xs">{{ $row->po_number }}</td>
              <td>{{ $row->customer->name ?? '-' }}</td>
              <td>{{ $row->customer->phone ?? '-' }}</td>
              <td>{{ $row->delivery_date ? \Carbon\Carbon::parse($row->delivery_date)->format('d/m/Y') : '-' }}</td>
              <td>{{ $row->delivery_time ?: '-' }}</td>
              <td>{{ $row->area->name ?? '-' }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="7" class="py-8 text-center text-sm text-slate-500">
                Tidak ada PO draft untuk dijadwalkan.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <section class="form-shell">
    <h2 class="text-base font-semibold text-slate-900">Jadwal Produksi</h2>
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
      <div>
        <label class="mb-1.5 block">Tanggal Produksi</label>
        <input
          type="date"
          name="schedule_date"
          value="{{ old('schedule_date', now()->toDateString()) }}"
          min="{{ now()->toDateString() }}"
          required
        >
      </div>

      <div>
        <label class="mb-1.5 block">Jam Produksi</label>
        <select name="slot_type" id="slotType" required>
          @foreach (UiLabel::spkSlotOptions() as $slotValue => $slotLabel)
            <option value="{{ $slotValue }}" @selected(old('slot_type') === $slotValue)>{{ $slotLabel }}</option>
          @endforeach
        </select>
      </div>

      <div id="customTimeWrap" class="hidden">
        <label class="mb-1.5 block">Jam Kustom</label>
        <input type="time" name="custom_time" value="{{ old('custom_time') }}">
      </div>
    </div>

    <div class="mt-4 flex items-center gap-2">
      <button class="btn-primary">Teruskan SPK ke Produksi</button>
      <span class="text-sm text-slate-500">Status awal SPK: <b>{{ UiLabel::spkStatus('in_process') }}</b></span>
    </div>
  </section>
</form>

<script>
(function () {
  const slot = document.getElementById('slotType');
  const wrap = document.getElementById('customTimeWrap');
  const checks = document.querySelectorAll('.po-check');
  const selectedCount = document.getElementById('selectedPoCount');

  function toggleCustomTime() {
    if (!slot || !wrap) return;
    wrap.classList.toggle('hidden', slot.value !== 'custom');
  }

  function syncCount() {
    if (!selectedCount) return;
    let count = 0;
    checks.forEach((cb) => {
      if (cb.checked) count++;
    });
    selectedCount.textContent = String(count);
  }

  if (slot) {
    slot.addEventListener('change', toggleCustomTime);
    toggleCustomTime();
  }

  checks.forEach((cb) => cb.addEventListener('change', syncCount));
  syncCount();
})();
</script>
@endsection
