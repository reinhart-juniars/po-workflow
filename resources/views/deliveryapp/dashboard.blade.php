@extends('layouts.deliveryapp', ['title' => 'Dashboard'])

@section('content')
@php
  use App\Support\UiLabel;
  $readyCount = $dos->where('status', 'ready')->count();
  $onDeliveryCount = $dos->where('status', 'on_delivery')->count();
@endphp

<section class="app-card p-4 sm:p-5">
  <div class="space-y-2">
    <div>
      <h1 class="text-xl font-semibold text-slate-900">Delivery Hari Ini</h1>
      <p class="text-sm text-slate-500">
        DO yang siap jalan dan yang sedang diantar.
      </p>
    </div>

    <div class="flex flex-wrap gap-2">
      <span class="chip bg-slate-100 text-slate-700">
        Total {{ number_format($dos->count()) }}
      </span>
      <span class="chip bg-amber-100 text-amber-700">
        {{ UiLabel::deliveryStatus('ready') }} {{ number_format($readyCount) }}
      </span>
      <span class="chip bg-sky-100 text-sky-700">
        {{ UiLabel::deliveryStatus('on_delivery') }} {{ number_format($onDeliveryCount) }}
      </span>
    </div>
  </div>
</section>

<section class="space-y-3">
  @forelse ($dos as $row)
    @php
      $primaryPo = $row->purchaseOrders->first();
      $recipient = $primaryPo?->recipient_name ?? $primaryPo?->customer?->name ?? '-';
      $phone = $primaryPo?->customer?->phone ?? '-';
      $address = $primaryPo?->shipping_address ?? '-';
      $statusClass = match ($row->status) {
        'ready' => 'bg-amber-100 text-amber-700',
        'on_delivery' => 'bg-sky-100 text-sky-700',
        'delivered' => 'bg-emerald-100 text-emerald-700',
        default => 'bg-slate-100 text-slate-600',
      };
      $statusLabel = match ($row->status) {
        'ready' => UiLabel::deliveryStatus('ready'),
        'on_delivery' => UiLabel::deliveryStatus('on_delivery'),
        'delivered' => UiLabel::deliveryStatus('delivered'),
        default => UiLabel::deliveryStatus($row->status),
      };
    @endphp

    <article class="app-card p-4">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="font-mono text-xs text-slate-500">{{ $row->do_code }}</div>
          <h2 class="mt-1 text-base font-semibold text-slate-900">{{ $recipient }}</h2>
        </div>
        <span class="chip {{ $statusClass }}">{{ $statusLabel }}</span>
      </div>

      <div class="mt-3 space-y-2 text-sm text-slate-600">
        <div><span class="font-medium text-slate-800">Telepon:</span> {{ $phone }}</div>
        <div><span class="font-medium text-slate-800">Area:</span> {{ $row->area->name ?? '-' }}</div>
        <div><span class="font-medium text-slate-800">Jadwal:</span> {{ $row->scheduled_at?->format('d M Y H:i') ?? '-' }}</div>
        <div class="line-clamp-2"><span class="font-medium text-slate-800">Alamat:</span> {{ $address }}</div>
      </div>

      <div class="mt-4">
        <a href="{{ route('deliveryapp.orders.show', $row->id) }}" class="btn-primary w-full">
          Buka Detail DO
        </a>
      </div>
    </article>
  @empty
    <div class="app-card p-6 text-center text-sm text-slate-500">
      Belum ada DO dengan status {{ UiLabel::deliveryStatus('ready') }} atau {{ UiLabel::deliveryStatus('on_delivery') }}.
    </div>
  @endforelse
</section>
@endsection
