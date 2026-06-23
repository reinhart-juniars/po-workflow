@extends('layouts.accountingapp', ['title' => 'Closing Penjualan'])

@section('content')
    @php
        $formatCurrency = fn(float $amount): string => 'Rp ' . number_format($amount, 0, ',', '.');
        $grossAmount = (float) $preview['grossAmount'];
        $receivableAmount = (float) ($preview['receivableAmount'] ?? 0);
        $grandTotalAmount = (float) ($preview['grandTotalAmount'] ?? $grossAmount);
        $receivableRowsByCustomer = $preview['receivableRowsByCustomer'] ?? collect();
        $oldDiscount = (float) old('discount_amount', 0);
        $netPreview = max(0, $grossAmount - $oldDiscount);
    @endphp

    <section class="dashboard-hero">
        <div class="page-toolbar">
            <div>
                <h1 class="dashboard-hero-title">Closing Penjualan</h1>
                <p class="dashboard-hero-subtitle">
                    Posting sales actual tunai harian ke cash in Accounting setelah diskon penjualan final diisi.
                </p>
            </div>
        </div>

        <form method="GET" action="{{ route('accountingapp.sales-closings.index') }}" class="form-grid mt-6">
            <div>
                <label class="form-label">Tanggal Penjualan</label>
                <input type="date" name="closing_date" value="{{ $closingDate->toDateString() }}" class="form-control">
            </div>

            <div class="flex items-end">
                <button type="submit" class="btn-primary w-full">Tampilkan</button>
            </div>
        </form>
    </section>

    @if ($isClosedPeriod)
        <section class="mt-6 notice-soft-amber">
            Periode {{ $closedPeriodLabel }} sudah ditutup. Closing penjualan untuk tanggal ini dikunci.
        </section>
    @endif

    @if ($existingClosing)
        <section class="mt-6 notice-soft-emerald">
            Tanggal ini sudah diposting oleh {{ $existingClosing->poster->name ?? '-' }} pada
            {{ $existingClosing->posted_at?->format('d-m-Y H:i') ?? '-' }}.
        </section>
    @endif

    <section class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-4">
        <div class="stat-card">
            <div class="stat-label">Sales Actual</div>
            <div class="stat-value">{{ number_format($preview['salesActualCount'], 0, ',', '.') }}</div>
            <div class="stat-meta">Belum diposting (tunai)</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Item</div>
            <div class="stat-value">{{ number_format($preview['itemCount'], 0, ',', '.') }}</div>
            <div class="stat-meta">Item tunai</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Gross Sales (Tunai)</div>
            <div class="stat-value">{{ $formatCurrency($grossAmount) }}</div>
            <div class="stat-meta">Sebelum diskon, hanya cash</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Calon Cash In</div>
            <div class="stat-value" data-sales-closing-net-preview>{{ $formatCurrency($netPreview) }}</div>
            <div class="stat-meta">Gross dikurangi diskon</div>
        </div>
    </section>

    <section class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rekonsiliasi dengan Laporan
                    Penjualan</div>
                <p class="mt-1 text-xs text-slate-500">
                    Yang akan diposting ke Cash In hanya bagian <strong>Tunai</strong>. Bagian <strong>Piutang</strong>
                    ditampilkan agar total penjualan sama dengan Laporan Penjualan tanggal
                    {{ $closingDate->format('d-m-Y') }}.
                </p>
            </div>
        </div>
        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Penjualan Tunai (Akan
                    Diposting)</div>
                <div class="mt-1 text-lg font-bold text-emerald-800">{{ $formatCurrency($grossAmount) }}</div>
                <div class="text-[11px] text-emerald-700/80">{{ number_format($preview['itemCount'], 0, ',', '.') }} item ·
                    {{ number_format($preview['salesActualCount'], 0, ',', '.') }} sales actual</div>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">Penjualan Piutang (Info Only)
                </div>
                <div class="mt-1 text-lg font-bold text-amber-800">{{ $formatCurrency($receivableAmount) }}</div>
                <div class="text-[11px] text-amber-700/80">
                    {{ number_format($preview['receivableItemCount'] ?? 0, 0, ',', '.') }} item ·
                    {{ number_format($preview['receivableSalesActualCount'] ?? 0, 0, ',', '.') }} sales actual
                </div>
            </div>
            <div class="rounded-lg border border-slate-300 bg-slate-50 p-3">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-600">Total Penjualan (Klop dengan
                    Laporan)</div>
                <div class="mt-1 text-lg font-bold text-slate-900">{{ $formatCurrency($grandTotalAmount) }}</div>
                <div class="text-[11px] text-slate-500">Tunai + Piutang</div>
            </div>
        </div>
        <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-600">Ongkos Kirim (Penjualan
                        Lain-Lain)</div>
                    <div class="text-[11px] text-slate-500">Diposting otomatis ke Cash In sebagai "Penjualan Lain-Lain"
                        per akun kas PO tunai. Untuk PO piutang, ongkir diposting saat pelunasan.</div>
                </div>
                <div class="text-right">
                    <div class="text-lg font-bold text-slate-900">{{ $formatCurrency((float) ($preview['shippingAmount'] ?? 0)) }}</div>
                    <div class="text-[10px] text-slate-500">Tunai
                        {{ $formatCurrency((float) ($preview['cashShippingAmount'] ?? 0)) }} · Piutang
                        {{ $formatCurrency((float) ($preview['receivableShippingAmount'] ?? 0)) }}</div>
                </div>
            </div>
        </div>
    </section>

    <section class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
        <div class="space-y-6">
            <div class="table-card">
                <div class="table-card-head">Ringkasan per Akun Kas</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">Akun Kas</th>
                                <th class="px-4 py-2 text-right">Sales Actual</th>
                                <th class="px-4 py-2 text-right">Item</th>
                                <th class="px-4 py-2 text-right">Gross</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($preview['rowsByCashAccount'] as $row)
                                <tr class="border-t">
                                    <td class="px-4 py-2">{{ $row['cash_account_name'] }}</td>
                                    <td class="px-4 py-2 text-right">
                                        {{ number_format($row['sales_actual_count'], 0, ',', '.') }}</td>
                                    <td class="px-4 py-2 text-right">{{ number_format($row['item_count'], 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-2 text-right">{{ $formatCurrency((float) $row['amount']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-6 text-center text-gray-500">
                                        Belum ada sales actual tunai yang siap diclosing pada tanggal ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="table-card">
                <div class="table-card-head">Ringkasan per Customer (Tunai)</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">Customer</th>
                                <th class="px-4 py-2 text-right">Item</th>
                                <th class="px-4 py-2 text-right">Gross</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($preview['rowsByCustomer'] as $row)
                                <tr class="border-t">
                                    <td class="px-4 py-2">{{ $row['customer_name'] }}</td>
                                    <td class="px-4 py-2 text-right">{{ number_format($row['item_count'], 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-2 text-right">{{ $formatCurrency((float) $row['amount']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 py-6 text-center text-gray-500">
                                        Belum ada detail customer untuk tanggal ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($receivableRowsByCustomer->isNotEmpty())
                <div class="table-card border-amber-200">
                    <div class="table-card-head bg-amber-50 text-amber-900">
                        Pembayaran Piutang (View Only — tidak diposting ke Cash In)
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-amber-50/60">
                                <tr>
                                    <th class="px-4 py-2 text-left">Customer</th>
                                    <th class="px-4 py-2 text-left">PO</th>
                                    <th class="px-4 py-2 text-left">Jatuh Tempo</th>
                                    <th class="px-4 py-2 text-right">Item</th>
                                    <th class="px-4 py-2 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($receivableRowsByCustomer as $row)
                                    <tr class="border-t">
                                        <td class="px-4 py-2">{{ $row['customer_name'] }}</td>
                                        <td class="px-4 py-2 text-slate-600">{{ $row['po_number'] ?? '-' }}</td>
                                        <td class="px-4 py-2 text-slate-600">
                                            {{ !empty($row['due_date']) ? \Carbon\Carbon::parse($row['due_date'])->format('d-m-Y') : '-' }}
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            {{ number_format($row['item_count'], 0, ',', '.') }}</td>
                                        <td class="px-4 py-2 text-right text-amber-800">
                                            {{ $formatCurrency((float) $row['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-amber-50/40">
                                <tr class="border-t font-semibold">
                                    <td colspan="4" class="px-4 py-2 text-right">Total Piutang (info)</td>
                                    <td class="px-4 py-2 text-right text-amber-900">
                                        {{ $formatCurrency($receivableAmount) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="px-4 pb-4 text-xs text-amber-800/80">
                        Piutang ini muncul di Laporan Penjualan tapi tidak masuk Cash In Accounting. Penagihan dan posting
                        kas-nya dilakukan saat pembayaran piutang diterima.
                    </div>
                </div>
            @endif
        </div>

        <div class="section-card">
            <h2 class="text-lg font-semibold text-slate-900">Posting Cash In</h2>
            <form method="POST" action="{{ route('accountingapp.sales-closings.store') }}" class="mt-4 space-y-4"
                data-sales-closing-form data-gross-amount="{{ $grossAmount }}">
                @csrf
                <input type="hidden" name="closing_date" value="{{ $closingDate->toDateString() }}">

                <div>
                    <label class="form-label">Tanggal Closing</label>
                    <input type="date" name="cash_in_date" value="{{ old('cash_in_date', now()->toDateString()) }}"
                        class="form-control" required>
                    <div class="field-help">Tanggal cash in akan diposting (boleh berbeda dari tanggal penjualan, mis.
                        closing H+1).</div>
                    @error('cash_in_date')
                        <div class="text-sm text-rose-700 mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <label class="form-label mb-0">Diskon Penjualan</label>
                        <div class="inline-flex overflow-hidden rounded-md border border-slate-300 text-xs font-medium"
                            role="tablist" aria-label="Mode input diskon">
                            <button type="button" data-discount-mode="amount" class="px-3 py-1 bg-slate-900 text-white"
                                aria-pressed="true">Rp</button>
                            <button type="button" data-discount-mode="percent" class="px-3 py-1 bg-white text-slate-700"
                                aria-pressed="false">%</button>
                        </div>
                    </div>

                    <div class="mt-1" data-discount-field="amount">
                        <input type="number" name="discount_amount" min="0" max="{{ $grossAmount }}"
                            step="0.01" value="{{ old('discount_amount', 0) }}" class="form-control"
                            data-sales-closing-discount>
                    </div>

                    <div class="mt-1 hidden" data-discount-field="percent">
                        <div class="relative">
                            <input type="number" min="0" max="100" step="0.01" value="0"
                                class="form-control pr-8" data-sales-closing-discount-percent>
                            <span
                                class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-500">%</span>
                        </div>
                    </div>

                    <div class="field-help">
                        Diskon mengurangi cash in dan pendapatan pada laporan laba rugi.
                        <span data-discount-equivalent class="ml-1 text-slate-500"></span>
                    </div>
                    @error('discount_amount')
                        <div class="text-sm text-rose-700 mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm">
                        <div class="text-xs uppercase tracking-wide text-amber-700">Tanggal Penjualan yang Diposting</div>
                        <div class="mt-0.5 text-base font-bold text-amber-900">
                            {{ $closingDate->format('d-m-Y') }}
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <div class="metric-label">Jumlah Transaksi</div>
                            <div class="mt-1 text-base font-bold text-slate-900">
                                {{ number_format($preview['salesActualCount'], 0, ',', '.') }} sales actual
                            </div>
                        </div>
                        <div>
                            <div class="metric-label">Calon Cash In</div>
                            <div class="mt-1 text-base font-bold text-emerald-700" data-sales-closing-net-preview>
                                {{ $formatCurrency($netPreview) }}
                            </div>
                        </div>
                        <div>
                            <div class="metric-label">Gross Sales</div>
                            <div class="mt-1 font-semibold text-slate-900">{{ $formatCurrency($grossAmount) }}</div>
                        </div>
                        <div>
                            <div class="metric-label">Diskon</div>
                            <div class="mt-1 font-semibold text-rose-700" data-sales-closing-discount-preview>
                                {{ $formatCurrency($oldDiscount) }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-xs text-slate-500">
                        Pastikan tanggal, jumlah transaksi, dan nilai cash in di atas sama dengan uang yang benar-benar
                        masuk sebelum posting.
                    </div>
                </div>

                <div>
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" rows="3" class="form-control">{{ old('notes') }}</textarea>
                </div>

                @error('closing_date')
                    <div class="text-sm text-rose-700">{{ $message }}</div>
                @enderror

                <button type="submit" class="btn-primary w-full" @disabled($grossAmount <= 0 || $existingClosing || $isClosedPeriod)>
                    Post ke Cash In Accounting
                </button>
            </form>
        </div>
    </section>

    <section class="table-card mt-6">
        <div class="table-card-head">Riwayat Closing</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Tanggal</th>
                        <th class="px-4 py-2 text-right">Gross</th>
                        <th class="px-4 py-2 text-right">Diskon</th>
                        <th class="px-4 py-2 text-right">Net</th>
                        <th class="px-4 py-2 text-left">Posted By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentClosings as $closing)
                        <tr class="border-t">
                            <td class="px-4 py-2">{{ $closing->closing_date->format('d-m-Y') }}</td>
                            <td class="px-4 py-2 text-right">{{ $formatCurrency((float) $closing->gross_amount) }}</td>
                            <td class="px-4 py-2 text-right">{{ $formatCurrency((float) $closing->discount_amount) }}</td>
                            <td class="px-4 py-2 text-right">{{ $formatCurrency((float) $closing->net_amount) }}</td>
                            <td class="px-4 py-2">{{ $closing->poster->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-gray-500">Belum ada closing penjualan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-3">
            {{ $recentClosings->links() }}
        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-sales-closing-form]');
            if (!form) return;

            const grossAmount = Number.parseFloat(form.dataset.grossAmount || '0') || 0;
            const discountInput = form.querySelector('[data-sales-closing-discount]');
            const percentInput = form.querySelector('[data-sales-closing-discount-percent]');
            const discountPreviews = document.querySelectorAll('[data-sales-closing-discount-preview]');
            const netPreviews = document.querySelectorAll('[data-sales-closing-net-preview]');
            const equivalentEl = form.querySelector('[data-discount-equivalent]');
            const modeButtons = form.querySelectorAll('[data-discount-mode]');
            const fields = {
                amount: form.querySelector('[data-discount-field="amount"]'),
                percent: form.querySelector('[data-discount-field="percent"]'),
            };

            const formatter = new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                maximumFractionDigits: 0,
            });
            const percentFormatter = new Intl.NumberFormat('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2,
            });

            const normalizeAmount = (value) => {
                const parsed = Number.parseFloat(value || '0');
                if (!Number.isFinite(parsed)) return 0;
                return Math.max(0, parsed);
            };

            const clampPercent = (value) => {
                const parsed = Number.parseFloat(value || '0');
                if (!Number.isFinite(parsed)) return 0;
                return Math.min(100, Math.max(0, parsed));
            };

            let mode = 'amount';

            const render = () => {
                const discountAmount = Math.min(grossAmount, normalizeAmount(discountInput?.value));
                const netAmount = Math.max(0, grossAmount - discountAmount);
                const percent = grossAmount > 0 ? (discountAmount / grossAmount) * 100 : 0;

                discountPreviews.forEach((el) => {
                    el.textContent = formatter.format(discountAmount);
                });
                netPreviews.forEach((el) => {
                    el.textContent = formatter.format(netAmount);
                });

                if (equivalentEl) {
                    if (mode === 'percent') {
                        equivalentEl.textContent = `≈ ${formatter.format(discountAmount)}`;
                    } else if (grossAmount > 0 && discountAmount > 0) {
                        equivalentEl.textContent = `≈ ${percentFormatter.format(percent)}% dari gross`;
                    } else {
                        equivalentEl.textContent = '';
                    }
                }
            };

            discountInput?.addEventListener('input', () => {
                if (discountInput.value && Number.parseFloat(discountInput.value) > grossAmount) {
                    discountInput.value = grossAmount;
                }
                if (percentInput && grossAmount > 0) {
                    const pct = (normalizeAmount(discountInput.value) / grossAmount) * 100;
                    percentInput.value = percentFormatter.format(clampPercent(pct)).replace(/\./g, '')
                        .replace(/,/g, '.');
                }
                render();
            });

            percentInput?.addEventListener('input', () => {
                const pct = clampPercent(percentInput.value);
                if (Number.parseFloat(percentInput.value) > 100) percentInput.value = 100;
                const amount = Math.round((grossAmount * pct) / 100);
                if (discountInput) discountInput.value = amount;
                render();
            });

            const setMode = (next) => {
                mode = next;
                modeButtons.forEach((btn) => {
                    const active = btn.dataset.discountMode === next;
                    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                    btn.classList.toggle('bg-slate-900', active);
                    btn.classList.toggle('text-white', active);
                    btn.classList.toggle('bg-white', !active);
                    btn.classList.toggle('text-slate-700', !active);
                });
                if (fields.amount) fields.amount.classList.toggle('hidden', next !== 'amount');
                if (fields.percent) fields.percent.classList.toggle('hidden', next !== 'percent');
                render();
            };

            modeButtons.forEach((btn) => {
                btn.addEventListener('click', () => setMode(btn.dataset.discountMode));
            });

            render();
        });
    </script>
@endsection
