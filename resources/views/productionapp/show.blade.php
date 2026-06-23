@extends('layouts.productionapp', ['title' => 'Detail PO'])

@section('content')
@php
    use App\Support\UiLabel;

    $schedule = $scheduledAt
        ? \Carbon\Carbon::parse($scheduledAt)
        : null;
@endphp

<section class="dashboard-hero">
  <div class="page-toolbar">
    <div>
      <h1 class="dashboard-hero-title">Detail PO {{ $po->po_number }}</h1>
      <p class="dashboard-hero-subtitle">
        Cek detail menu produksi, jadwal, dan selesaikan PO saat proses produksi benar-benar selesai.
      </p>
    </div>
    <a href="{{ route('productionapp.dashboard') }}" class="btn-ghost">
      Kembali ke Dashboard
    </a>
  </div>
</section>

<section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 mt-4">
  <article class="stat-card">
    <p class="stat-label">Customer</p>
    <p class="text-base font-semibold text-slate-900">{{ $po->customer->name ?? '-' }}</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Area</p>
    <p class="text-base font-semibold text-slate-900">{{ $po->area->name ?? '-' }}</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Tanggal Produksi</p>
    <p class="text-base font-semibold text-slate-900">{{ $schedule ? $schedule->format('d/m/Y') : '-' }}</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Jam Produksi</p>
    <p class="text-base font-semibold text-slate-900">{{ $schedule ? $schedule->format('H:i') : '-' }}</p>
  </article>
</section>

<section class="mt-4 space-y-3 lg:hidden">
  <div>
    <h2 class="section-title">Daftar Menu</h2>
    <p class="section-subtitle">Tampilan ringkas untuk HP agar tiap item lebih mudah dicek saat produksi.</p>
  </div>

  @forelse ($po->items as $it)
    <article class="section-card">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-sm font-semibold text-slate-900">{{ $it->product->name ?? '-' }}</p>
          <p class="mt-1 text-xs text-slate-500">Qty produksi</p>
        </div>
        <span class="badge-soft badge-soft-emerald">{{ number_format((float) $it->qty, 0, ',', '.') }}</span>
      </div>

      <div class="mt-4">
        <p class="metric-label">Catatan</p>
        <p class="text-sm leading-relaxed text-slate-700">{{ $it->notes ?: '-' }}</p>
      </div>
    </article>
  @empty
    <div class="section-card text-sm text-slate-500">
      Tidak ada item.
    </div>
  @endforelse
</section>

<section class="table-shell mt-4 hidden lg:block">
  <div class="table-head">Daftar Menu</div>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Menu</th>
          <th class="text-right">Qty</th>
          <th>Catatan</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($po->items as $it)
          <tr>
            <td>{{ $it->product->name ?? '-' }}</td>
            <td class="text-right">{{ $it->qty }}</td>
            <td>{{ $it->notes ?? '-' }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="3" class="px-4 py-6 text-center text-slate-500">
              Tidak ada item.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>

<section class="section-card mt-4">
  <div class="page-toolbar gap-4">
    <div>
      <p class="metric-label">Aksi Produksi</p>
      @if ($po->status === 'in_progress')
        <p class="text-sm text-slate-600">Tandai selesai saat semua item siap, atau batalkan jika customer membatalkan PO secara mendadak.</p>
      @elseif ($po->status === 'cancelled')
        <p class="text-sm text-slate-600">PO ini sudah {{ UiLabel::purchaseOrderStatus('cancelled') }}.</p>
        @if ($po->cancel_reason)
          <p class="mt-2 text-xs text-slate-500"><span class="font-semibold text-slate-700">Alasan:</span> {{ $po->cancel_reason }}</p>
        @endif
      @else
        <p class="text-sm text-slate-600">PO ini sudah berstatus {{ UiLabel::purchaseOrderStatus($po->status) }}.</p>
      @endif
    </div>

    <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
      @if ($po->status === 'in_progress')
        <form id="completeForm"
              action="{{ route('productionapp.orders.complete', $po->id) }}"
              method="POST">
          @csrf
          <button
            type="button"
            id="btnCompleteDesktop"
            class="btn-primary hidden w-full sm:w-auto lg:inline-flex"
            title="Tandai {{ UiLabel::purchaseOrderStatus('completed') }}"
          >
            Tandai Selesai
          </button>
        </form>
        <button
          type="button"
          id="btnCancelDesktop"
          class="hidden w-full items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100 sm:w-auto lg:inline-flex"
          title="Batalkan PO"
        >
          Batalkan PO
        </button>
      @elseif ($po->status === 'cancelled')
        <div class="badge-soft badge-soft-rose">
          {{ UiLabel::purchaseOrderStatus('cancelled') }}
        </div>
      @else
        <div class="badge-soft badge-soft-slate">
          Sudah {{ UiLabel::purchaseOrderStatus($po->status) }}
        </div>
      @endif
    </div>
  </div>
</section>

@if ($po->status === 'in_progress')
  <div
    id="cancelModal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 px-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="cancelModalTitle"
  >
    <form
      action="{{ route('productionapp.orders.cancel', $po->id) }}"
      method="POST"
      class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl"
    >
      @csrf
      <h2 id="cancelModalTitle" class="text-base font-semibold text-slate-900">
        Batalkan PO {{ $po->po_number }}?
      </h2>
      <p class="mt-1 text-sm text-slate-600">
        Aksi ini akan menandai PO sebagai {{ UiLabel::purchaseOrderStatus('cancelled') }} dan melepasnya dari SPK terkait. Tidak bisa dikembalikan.
      </p>

      <label for="cancel_reason" class="mt-4 block text-sm font-semibold text-slate-700">
        Alasan pembatalan <span class="text-rose-600">*</span>
      </label>
      <textarea
        id="cancel_reason"
        name="cancel_reason"
        rows="3"
        required
        minlength="3"
        maxlength="1000"
        placeholder="Contoh: Customer membatalkan via WhatsApp karena acara mundur."
        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
      >{{ old('cancel_reason') }}</textarea>
      @error('cancel_reason')
        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
      @enderror

      <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button
          type="button"
          data-cancel-modal-close
          class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
        >
          Tutup
        </button>
        <button
          type="submit"
          class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500"
        >
          Ya, Batalkan PO
        </button>
      </div>
    </form>
  </div>
@endif

@if ($po->status === 'in_progress')
  <div class="h-24 lg:hidden"></div>

  <div class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 px-4 py-3 shadow-[0_-12px_30px_-18px_rgba(15,23,42,0.45)] backdrop-blur lg:hidden">
    <div class="page-wrap px-0">
      <div class="flex items-center gap-2">
        <button
          type="button"
          id="btnCancelMobile"
          class="min-w-[110px] rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100"
          title="Batalkan PO"
        >
          Batalkan
        </button>
        <button
          type="button"
          id="btnCompleteMobile"
          class="btn-primary flex-1"
          title="Tandai {{ UiLabel::purchaseOrderStatus('completed') }}"
        >
          Tandai Selesai
        </button>
      </div>
    </div>
  </div>
@endif

<script>
  (() => {
    const completeButtons = [
      document.getElementById('btnCompleteDesktop'),
      document.getElementById('btnCompleteMobile'),
    ].filter(Boolean);

    completeButtons.forEach((button) => {
      button.addEventListener('click', () => {
        if (confirm('Apakah Anda sudah menyelesaikan PO ini?')) {
          document.getElementById('completeForm').submit();
        }
      });
    });

    const modal = document.getElementById('cancelModal');
    if (!modal) return;

    const openButtons = [
      document.getElementById('btnCancelDesktop'),
      document.getElementById('btnCancelMobile'),
    ].filter(Boolean);
    const closeButtons = modal.querySelectorAll('[data-cancel-modal-close]');
    const reasonInput = modal.querySelector('#cancel_reason');

    const openModal = () => {
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      setTimeout(() => reasonInput?.focus(), 50);
    };
    const closeModal = () => {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
    };

    openButtons.forEach((btn) => btn.addEventListener('click', openModal));
    closeButtons.forEach((btn) => btn.addEventListener('click', closeModal));

    modal.addEventListener('click', (event) => {
      if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });

    @if ($errors->any())
      openModal();
    @endif
  })();
</script>
@endsection
