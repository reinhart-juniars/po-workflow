@extends('layouts.accountingapp', ['title' => 'Tagihan '.$bill->number])

{{--
  Detail Tagihan Pembelian + keputusan accounting. Tiga aksi saling eksklusif:
  Bayar (kas keluar sebagai pelunasan hutang), Jadikan hutang supplier
  (jatuh tempo), Kembalikan ke gudang (alasan). Tanpa Alpine: <details>.
--}}
@php
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0';
    $badge = match ($bill->status) {
        \App\Models\PurchaseBill::STATUS_SUBMITTED => 'badge-soft-amber',
        \App\Models\PurchaseBill::STATUS_CREDIT => 'badge-soft-brand',
        \App\Models\PurchaseBill::STATUS_PAID => 'badge-soft-emerald',
        \App\Models\PurchaseBill::STATUS_RETURNED => 'badge-soft-rose',
        default => 'badge-soft-slate',
    };
    $isImage = $bill->receipt_path && in_array(strtolower(pathinfo($bill->receipt_path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
@endphp

@section('content')
    <div class="page-toolbar">
        <div>
            <p class="text-sm text-slate-500">
                <a href="{{ route('accountingapp.purchase-bills.index') }}" class="hover:underline">Tagihan Pembelian</a> ›
            </p>
            <h1 class="mt-1">{{ $bill->number }}</h1>
            <p class="section-subtitle">
                {{ $bill->displaySupplier() }} · {{ $bill->bill_date->translatedFormat('d M Y') }} ·
                {{ $bill->requisition ? $bill->requisition->number.' ('.$bill->requisition->productionOrder?->number.')' : 'Belanja lepas' }}
            </p>
        </div>
        <div class="toolbar-actions">
            <span class="{{ $badge }} self-start">{{ $bill->statusLabel() }}</span>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-success mt-4">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash-error mt-4" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="bill-layout mt-6">
        <div class="bill-main">
            <section class="table-shell">
                <div class="table-head">Bahan yang ditagihkan</div>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Bahan</th><th>Kondisi</th><th class="text-right">Jumlah</th><th class="text-right">Harga</th><th class="text-right">Nilai</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($bill->purchases as $purchase)
                                <tr>
                                    <td>{{ $purchase->item?->name ?? '-' }}</td>
                                    <td>
                                        <span class="{{ $purchase->isDamaged() ? 'badge-soft-rose' : 'badge-soft-slate' }}">{{ $purchase->conditionLabel() }}</span>
                                        @if ($purchase->condition_notes)
                                            <div class="mt-1 text-xs text-slate-500">{{ $purchase->condition_notes }}</div>
                                        @endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">{{ $qty($purchase->qty) }} {{ $purchase->item?->unit }}</td>
                                    <td class="text-right whitespace-nowrap">{{ $rp($purchase->unit_cost) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ $rp($purchase->total_value) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-slate-200">
                                <td colspan="4" class="px-3 py-4 pl-6 text-right font-semibold">Total tagihan</td>
                                <td class="px-3 py-4 pr-6 text-right font-semibold whitespace-nowrap">{{ $rp($bill->total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            @if ($bill->notes)
                <section class="section-card mt-4">
                    <h2 class="section-title">Catatan gudang</h2>
                    <p class="mt-2 text-sm text-slate-700">{{ $bill->notes }}</p>
                </section>
            @endif
        </div>

        <aside class="bill-side">
            <section class="section-card">
                <h2 class="section-title">Nota</h2>
                @if ($bill->receipt_path)
                    @if ($isImage)
                        <a href="{{ route('purchase-bills.receipt', $bill) }}" target="_blank" rel="noopener" class="mt-3 block overflow-hidden rounded-lg ring-1 ring-slate-950/5">
                            <img src="{{ route('purchase-bills.receipt', $bill) }}" alt="Nota {{ $bill->number }}" class="max-h-80 w-full object-contain bg-slate-50">
                        </a>
                    @endif
                    <a href="{{ route('purchase-bills.receipt', $bill) }}" target="_blank" rel="noopener" class="btn-gray mt-3 w-full">Buka nota</a>
                @else
                    <p class="mt-2 text-sm text-slate-500">Gudang belum melampirkan foto nota.</p>
                @endif
                <dl class="bill-meta mt-4">
                    <div><dt>Diajukan</dt><dd>{{ $bill->submitted_at ? $bill->submitted_at->translatedFormat('d M Y H:i').' · '.($bill->submitter?->name ?? '-') : '–' }}</dd></div>
                    @if ($bill->decided_at)
                        <div><dt>Diputuskan</dt><dd>{{ $bill->decided_at->translatedFormat('d M Y H:i').' · '.($bill->decider?->name ?? '-') }}</dd></div>
                    @endif
                    @if ($bill->status === \App\Models\PurchaseBill::STATUS_PAID)
                        <div><dt>Dibayar</dt><dd>{{ $bill->paid_on?->translatedFormat('d M Y') }} dari {{ $bill->cashAccount?->name }}</dd></div>
                    @elseif ($bill->due_date)
                        <div><dt>Jatuh tempo</dt><dd class="{{ $bill->due_date->isPast() ? 'text-rose-600' : '' }}">{{ $bill->due_date->translatedFormat('d M Y') }}</dd></div>
                    @endif
                    @if ($bill->return_reason)
                        <div><dt>Alasan dikembalikan</dt><dd>{{ $bill->return_reason }}</dd></div>
                    @endif
                </dl>
            </section>

            @if ($canDecide && $bill->isPayable())
                <section class="section-card mt-4">
                    <h2 class="section-title">Bayar tagihan</h2>
                    <p class="mt-1 text-sm text-slate-500">Kas keluar {{ $rp($bill->total) }} dicatat sebagai pelunasan hutang (bukan beban baru).</p>
                    <form method="POST" action="{{ route('accountingapp.purchase-bills.pay', $bill) }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <label for="cash_account_id" class="form-label">Akun kas</label>
                            <select id="cash_account_id" name="cash_account_id" required>
                                <option value="">Pilih akun kas</option>
                                @foreach ($cashAccounts as $account)
                                    <option value="{{ $account->id }}" @selected(old('cash_account_id') == $account->id)>{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="paid_on" class="form-label">Tanggal bayar</label>
                            <input id="paid_on" name="paid_on" type="date" value="{{ old('paid_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                        </div>
                        <button type="submit" class="btn-primary w-full">Bayar {{ $rp($bill->total) }}</button>
                    </form>
                </section>

                @if ($bill->status === \App\Models\PurchaseBill::STATUS_SUBMITTED)
                    <details class="section-card bill-more mt-4">
                        <summary>Belum dibayar sekarang</summary>
                        <form method="POST" action="{{ route('accountingapp.purchase-bills.credit', $bill) }}" class="mt-4 space-y-3">
                            @csrf
                            <div>
                                <label for="due_date" class="form-label">Hutang supplier, jatuh tempo</label>
                                <input id="due_date" name="due_date" type="date" value="{{ old('due_date', $defaultDueDate) }}" min="{{ now()->toDateString() }}" required>
                            </div>
                            <button type="submit" class="btn-gray w-full">Jadikan hutang supplier</button>
                        </form>
                        <form method="POST" action="{{ route('accountingapp.purchase-bills.return', $bill) }}" class="mt-6 space-y-3 border-t border-slate-200 pt-4">
                            @csrf
                            <div>
                                <label for="return_reason" class="form-label">Kembalikan ke gudang</label>
                                <textarea id="return_reason" name="return_reason" rows="2" required minlength="5" placeholder="Mis. nota belum dilampirkan, harga tidak sesuai">{{ old('return_reason') }}</textarea>
                            </div>
                            <button type="submit" class="btn-danger w-full">Kembalikan</button>
                        </form>
                    </details>
                @endif
            @endif
        </aside>
    </div>
@endsection
