@extends('layouts.deliveryapp', ['title' => 'Detail DO'])

@section('content')
@php
    use App\Support\UiLabel;
    $firstPo = $do->purchaseOrders->first();
    $badgeClass = match ($do->status) {
        'ready' => 'bg-amber-100 text-amber-700',
        'on_delivery' => 'bg-sky-100 text-sky-700',
        'delivered' => 'bg-emerald-100 text-emerald-700',
        default => 'bg-slate-100 text-slate-700',
    };
    $badgeLabel = match ($do->status) {
        'ready' => UiLabel::deliveryStatus('ready'),
        'on_delivery' => UiLabel::deliveryStatus('on_delivery'),
        'delivered' => UiLabel::deliveryStatus('delivered'),
        default => UiLabel::deliveryStatus($do->status),
    };
@endphp

<div class="page-toolbar">
    <div>
        <h1 class="text-xl font-semibold text-slate-900">Detail DO {{ $do->do_code }}</h1>
        <p class="text-sm text-slate-500">Informasi penerima, jadwal kirim, dan aksi pengiriman.</p>
    </div>
    <a href="{{ route('deliveryapp.dashboard') }}" class="btn-ghost">
        Kembali
    </a>
</div>

<div class="app-card p-4 sm:p-5">
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="font-mono text-xs text-slate-500">{{ $do->do_code }}</div>
            <div class="mt-1 text-lg font-semibold text-slate-900">
                {{ $firstPo?->recipient_name ?? ($firstPo?->customer?->name ?? '-') }}
            </div>
        </div>
        <span class="chip {{ $badgeClass }}">{{ $badgeLabel }}</span>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
            <div class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Telepon</div>
            <div class="mt-1 text-sm font-medium text-slate-800">{{ $firstPo?->customer?->phone ?? '-' }}</div>
        </div>
        <div>
            <div class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Area</div>
            <div class="mt-1 text-sm font-medium text-slate-800">{{ $do->area->name ?? '-' }}</div>
        </div>
        <div class="sm:col-span-2">
            <div class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Alamat Kirim</div>
            <div class="mt-1 text-sm font-medium text-slate-800">{{ $firstPo?->shipping_address ?? '-' }}</div>
        </div>
        <div>
            <div class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Jadwal Kirim</div>
            <div class="mt-1 text-sm font-medium text-slate-800">
                {{ $do->scheduled_at ? \Carbon\Carbon::parse($do->scheduled_at)->format('d/m/Y H:i') : '-' }}
            </div>
        </div>
        <div>
            <div class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Jumlah PO</div>
            <div class="mt-1 text-sm font-medium text-slate-800">{{ $do->purchaseOrders->count() }}</div>
        </div>
    </div>
</div>

<div class="app-card p-4 sm:p-5">
    <div class="section-title mb-3">Aksi Pengiriman</div>
    <div class="space-y-3">
        @if ($do->status === 'ready')
            <form id="formStart" action="{{ route('deliveryapp.orders.start', $do->id) }}" method="POST">
                @csrf
                <button
                    type="button"
                    id="btnStart"
                    class="w-full rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-amber-600"
                >
                    Mulai Pengiriman
                </button>
            </form>
            <p class="text-sm text-slate-500">Tekan tombol ini saat barang mulai dibawa ke tujuan.</p>

        @elseif ($do->status === 'on_delivery')
            <form id="formDone" action="{{ route('deliveryapp.orders.done', $do->id) }}" method="POST">
                @csrf
                <button
                    type="button"
                    id="btnDone"
                    class="btn-primary w-full !bg-emerald-600 hover:!bg-emerald-700"
                >
                    Selesaikan Pengiriman
                </button>
            </form>

            <form id="formCancel" action="{{ route('deliveryapp.orders.cancel', $do->id) }}" method="POST">
                @csrf
                <button
                    type="button"
                    id="btnCancel"
                    class="w-full rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100"
                >
                    Batalkan Pengiriman
                </button>
            </form>

            <p class="text-sm text-slate-500">Selesaikan jika barang sudah diterima. Batalkan jika pengiriman belum jadi.</p>

        @else
            <div class="inline-flex items-center justify-center rounded-xl bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700">
                {{ UiLabel::deliveryStatus('delivered') }}
            </div>
            <p class="text-sm text-slate-500">Pengiriman sudah selesai.</p>
        @endif
    </div>
</div>

<div class="app-card p-4 sm:p-5">
    <div class="section-title mb-3">Daftar PO</div>
    <div class="space-y-3">
        @forelse ($do->purchaseOrders as $po)
            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                <div class="font-mono text-xs text-slate-500">{{ $po->po_number }}</div>
                <div class="mt-1 text-sm font-semibold text-slate-900">{{ $po->customer->name ?? '-' }}</div>
                <div class="mt-2 grid grid-cols-2 gap-2 text-sm text-slate-600">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.08em] text-slate-400">Tanggal</div>
                        <div>{{ $po->delivery_date ? \Carbon\Carbon::parse($po->delivery_date)->format('d/m/Y') : '-' }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.08em] text-slate-400">Jam</div>
                        <div>{{ $po->delivery_time ?? '-' }}</div>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-sm text-slate-500">
                Tidak ada PO terkait.
            </div>
        @endforelse
    </div>
</div>

<script>
    (function () {
        const btnStart = document.getElementById('btnStart');
        const btnCancel = document.getElementById('btnCancel');
        const btnDone = document.getElementById('btnDone');

        if (btnStart) {
            btnStart.addEventListener('click', function () {
                if (confirm('Mulai pengiriman untuk DO ini?')) {
                    document.getElementById('formStart').submit();
                }
            });
        }

        if (btnCancel) {
            btnCancel.addEventListener('click', function () {
                if (confirm('Batalkan pengiriman untuk DO ini?')) {
                    document.getElementById('formCancel').submit();
                }
            });
        }

        if (btnDone) {
            btnDone.addEventListener('click', function () {
                if (confirm('Tandai pengiriman ini sudah selesai?')) {
                    document.getElementById('formDone').submit();
                }
            });
        }
    })();
</script>
@endsection
