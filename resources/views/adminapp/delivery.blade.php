@extends('layouts.adminapp', ['title' => 'Delivery'])

@section('content')
@php use App\Support\UiLabel; @endphp
<section class="dashboard-hero">
  <h1 class="dashboard-hero-title">Setup Delivery</h1>
  <p class="dashboard-hero-subtitle">
    Pilih PO selesai yang belum punya DO, tentukan area, driver, dan jadwal kirim untuk membuat delivery order.
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

<section class="table-shell mt-4">
  <div class="table-head flex flex-wrap items-center justify-between gap-2">
    <span>PO Selesai - Belum Dijadwalkan DO</span>
    <div class="flex items-center gap-3 text-xs">
      <label class="inline-flex items-center gap-2 font-semibold text-slate-600">
        <input id="checkAllPo" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500/30">
        Pilih semua
      </label>
      <span class="font-semibold text-slate-500">Terpilih: <span id="selectedDeliveryCount">0</span></span>
    </div>
  </div>

  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Pilih</th>
          <th>PO Number</th>
          <th>Penerima</th>
          <th>Alamat</th>
          <th>Tanggal Kirim</th>
          <th>Jam Terima</th>
          <th>Area</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($completedPO as $po)
          <tr>
            <td>
              <input type="checkbox" value="{{ $po->id }}" class="po-check h-4 w-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500/30">
            </td>
            <td class="font-mono text-xs">{{ $po->po_number }}</td>
            <td>{{ $po->recipient_name ?? '-' }}</td>
            <td class="max-w-xs truncate" title="{{ $po->shipping_address }}">{{ $po->shipping_address ?? '-' }}</td>
            <td>{{ $po->delivery_date ? \Carbon\Carbon::parse($po->delivery_date)->format('d M Y') : '-' }}</td>
            <td>{{ $po->delivery_time ?: '-' }}</td>
            <td>{{ $po->area->name ?? '-' }}</td>
          </tr>
        @empty
          <tr>
              <td colspan="7" class="py-8 text-center text-sm text-slate-500">
              Tidak ada PO selesai yang belum dijadwalkan.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>

<section class="form-shell mt-4">
  <h2 class="text-base font-semibold text-slate-900">Buat Delivery Order</h2>

  <form action="{{ route('adminapp.delivery.store') }}" method="POST" id="doForm" class="mt-4 space-y-5">
    @csrf

    <div id="poHiddenInputs"></div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
      <div>
        <label class="mb-1.5 block">Area</label>
        <select name="area_id" required>
          <option value="">-- Pilih Area --</option>
          @foreach ($areas as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="mb-1.5 block">Tanggal Kirim</label>
        <input type="date" name="schedule_date" value="{{ now()->toDateString() }}" required>
      </div>

      <div>
        <label class="mb-1.5 block">Jam Kirim</label>
        <input type="time" name="schedule_time" value="09:00" required>
      </div>

      <div class="md:col-span-3">
        <label class="mb-1.5 block">Driver</label>
        <select name="driver_user_id" required>
          <option value="">-- Pilih Driver --</option>
          @foreach ($drivers as $d)
            <option value="{{ $d->id }}">{{ $d->name }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <button type="submit" class="btn-primary">Teruskan ke Delivery</button>
      <span class="text-sm text-slate-500">Status awal DO: <b>{{ UiLabel::deliveryStatus('ready') }}</b></span>
    </div>
  </form>
</section>

<script>
(function () {
  const checkboxes = document.querySelectorAll('.po-check');
  const checkAll = document.getElementById('checkAllPo');
  const hiddenWrap = document.getElementById('poHiddenInputs');
  const form = document.getElementById('doForm');
  const selectedCount = document.getElementById('selectedDeliveryCount');

  function syncPoIds() {
    if (!hiddenWrap) return;
    hiddenWrap.innerHTML = '';

    checkboxes.forEach((cb) => {
      if (cb.checked) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'po_ids[]';
        input.value = cb.value;
        hiddenWrap.appendChild(input);
      }
    });

    if (selectedCount) {
      selectedCount.textContent = String(hiddenWrap.querySelectorAll('input[name="po_ids[]"]').length);
    }
  }

  function syncCheckAllState() {
    if (!checkAll) return;
    const total = checkboxes.length;
    const checked = Array.from(checkboxes).filter((cb) => cb.checked).length;
    checkAll.checked = total > 0 && checked === total;
  }

  checkboxes.forEach((cb) => {
    cb.addEventListener('change', () => {
      syncPoIds();
      syncCheckAllState();
    });
  });

  if (checkAll) {
    checkAll.addEventListener('change', () => {
      checkboxes.forEach((cb) => {
        cb.checked = checkAll.checked;
      });
      syncPoIds();
      syncCheckAllState();
    });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      syncPoIds();
      const count = hiddenWrap ? hiddenWrap.querySelectorAll('input[name="po_ids[]"]').length : 0;
      if (count === 0) {
        e.preventDefault();
        alert('Pilih minimal 1 PO selesai untuk dibuatkan DO.');
      }
    });
  }

  syncPoIds();
  syncCheckAllState();
})();
</script>
@endsection
