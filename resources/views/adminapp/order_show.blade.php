@extends('layouts.adminapp', ['title' => 'Detail PO'])

@section('content')
@php use App\Support\UiLabel; @endphp
@php $isDelivered = $po->hasDeliveredDeliveryOrder(); @endphp
@php $isEditLocked = $po->isCompleted() || $isDelivered; @endphp
<div class="page-toolbar">
  <div>
    <h1 class="section-title">Detail PO - {{ $po->po_number }}</h1>
    <p class="section-subtitle">Informasi header order dan rincian item pesanan.</p>
  </div>

  <div class="flex flex-wrap items-center gap-2">
    <span class="status-badge {{ $po->status === 'draft' ? 'bg-amber-100 text-amber-700' : ($po->status === 'in_progress' ? 'bg-sky-100 text-sky-700' : 'bg-emerald-100 text-emerald-700') }}">
      {{ UiLabel::purchaseOrderStatus($po->status) }}
    </span>

    @unless ($isEditLocked)
      <a href="{{ route('adminapp.orders.edit', $po->id) }}" class="btn-ghost">
        Edit PO
      </a>
    @endunless

    @if($po->status === 'draft')
      <form action="{{ route('adminapp.orders.destroy', $po->id) }}" method="POST" onsubmit="return confirm('Yakin ingin menghapus PO {{ $po->po_number }}?');">
        @csrf
        @method('DELETE')
        <input type="hidden" name="redirect" value="dashboard">
        <button type="submit" class="btn-ghost border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100">
          Hapus PO
        </button>
      </form>
    @endif
  </div>
</div>

<section class="form-shell mt-4">
  <div class="form-grid">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Customer</p>
      <p class="mt-1 font-semibold text-slate-800">{{ $po->customer->name ?? '-' }}</p>
    </div>
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">No Telepon</p>
      <p class="mt-1 font-semibold text-slate-800">{{ $po->customer->phone ?? '-' }}</p>
    </div>
    <div class="md:col-span-2">
      <p class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Alamat Kirim</p>
      <p class="mt-1 font-semibold text-slate-800">{{ $po->shipping_address }}</p>
    </div>
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Tanggal Kirim</p>
      <p class="mt-1 font-semibold text-slate-800">{{ $po->delivery_date ? \Carbon\Carbon::parse($po->delivery_date)->format('d M Y') : '-' }}</p>
    </div>
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Jam Terima</p>
      <p class="mt-1 font-semibold text-slate-800">{{ $po->delivery_time ?: '-' }}</p>
    </div>
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Jenis Pembayaran</p>
      <p class="mt-1 font-semibold text-slate-800">{{ $po->payment_type === 'receivable' ? 'Piutang' : 'Tunai' }}</p>
    </div>
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Tempo Piutang</p>
      <p class="mt-1 font-semibold text-slate-800">
        @if($po->payment_type === 'receivable' && $po->receivable_days)
          {{ $po->receivable_days }} hari
        @else
          -
        @endif
      </p>
    </div>
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Jatuh Tempo</p>
      <p class="mt-1 font-semibold text-slate-800">
        {{ $po->due_date ? \Carbon\Carbon::parse($po->due_date)->format('d M Y') : '-' }}
      </p>
    </div>
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Akun Kas</p>
      <p class="mt-1 font-semibold text-slate-800">{{ $po->cashAccount->name ?? '-' }}</p>
    </div>
  </div>
</section>

<section class="table-shell mt-4">
  <div class="table-head">Items</div>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Produk</th>
          <th class="text-right">Qty</th>
          <th class="text-right">Harga</th>
          <th class="text-right">Sub-total</th>
          <th>Catatan</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($po->items as $it)
          <tr>
            <td>{{ $it->product->name ?? '-' }}</td>
            <td class="text-right">{{ $it->qty }}</td>
            <td class="text-right">Rp {{ number_format($it->unit_price, 0, ',', '.') }}</td>
            <td class="text-right font-semibold">Rp {{ number_format($it->subtotal, 0, ',', '.') }}</td>
            <td class="text-xs text-slate-500">{{ $it->notes ?: '-' }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="py-8 text-center text-sm text-slate-500">Tidak ada item.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
