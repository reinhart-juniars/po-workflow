@extends($pageLayout)

@push('styles')
    <style>
        .production-report-wrap {
            overflow-x: auto;
            overflow-y: hidden;
            max-width: 100%;
            -webkit-overflow-scrolling: touch;
        }

        .production-report-table {
            width: max-content;
            min-width: 1280px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .production-report-table th,
        .production-report-table td {
            padding: 0.65rem 0.75rem;
            font-size: 12px;
            line-height: 1.35rem;
            vertical-align: top;
            border-top: 1px solid rgb(226 232 240);
            border-right: 1px solid rgb(226 232 240);
            white-space: normal;
        }

        .production-report-table th:first-child,
        .production-report-table td:first-child {
            border-left: 1px solid rgb(226 232 240);
        }

        .production-report-table thead th {
            background: rgb(226 232 240 / 0.96);
            text-align: left;
            font-weight: 800;
            color: rgb(15 23 42);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .production-col-code {
            min-width: 140px;
        }

        .production-col-schedule {
            min-width: 170px;
        }

        .production-col-customer {
            min-width: 220px;
        }

        .production-col-menu {
            min-width: 300px;
        }

        .production-col-price {
            min-width: 170px;
        }

        .production-col-notes {
            min-width: 280px;
        }

        .production-col-status {
            min-width: 130px;
        }

        .production-stack {
            display: grid;
            gap: 0.35rem;
        }

        .production-stack-row {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            border-bottom: 1px dashed rgb(226 232 240);
            padding-bottom: 0.25rem;
        }

        .production-stack-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .production-stack-label {
            color: rgb(15 23 42);
            font-weight: 600;
        }

        .production-stack-value {
            color: rgb(51 65 85);
            font-weight: 700;
            text-align: right;
            white-space: nowrap;
        }

        .production-stack-note {
            color: rgb(71 85 105);
        }

        .production-price-total {
            margin-top: 0.5rem;
            border-top: 1px solid rgb(203 213 225);
            padding-top: 0.45rem;
            text-align: right;
            font-weight: 800;
            color: rgb(15 23 42);
        }

        .production-summary-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
        }

        .production-summary-table th,
        .production-summary-table td {
            padding: 0.75rem;
            font-size: 12px;
            line-height: 1.4rem;
            vertical-align: top;
            border-top: 1px solid rgb(226 232 240);
            border-right: 1px solid rgb(226 232 240);
            word-break: break-word;
            white-space: normal;
        }

        .production-summary-table th:first-child,
        .production-summary-table td:first-child {
            border-left: 1px solid rgb(226 232 240);
        }

        .production-summary-table thead th {
            background: rgb(226 232 240 / 0.96);
            text-align: left;
            font-weight: 800;
            color: rgb(15 23 42);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .production-summary-details {
            display: grid;
            gap: 0.4rem;
        }

        .production-summary-line {
            display: grid;
            gap: 0.15rem;
        }

        .production-summary-label {
            font-size: 11px;
            font-weight: 800;
            color: rgb(71 85 105);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .production-summary-value {
            color: rgb(15 23 42);
            font-weight: 600;
        }

        .production-summary-toggle summary {
            cursor: pointer;
            color: rgb(37 99 235);
            font-weight: 700;
            list-style: none;
        }

        .production-summary-toggle summary::-webkit-details-marker {
            display: none;
        }

        .production-summary-toggle-body {
            margin-top: 0.6rem;
        }
    </style>
@endpush

@section('content')
    @php
        use App\Support\UiLabel;
        $inProcessCount = $spks->where('status', 'in_process')->count();
        $completedCount = $spks->where('status', 'completed')->count();
        $statusLabels = UiLabel::spkStatusOptions();
    @endphp

    <section class="dashboard-hero">
        <div class="page-toolbar">
            <div>
                <h1 class="dashboard-hero-title">Laporan Produksi (SPK)</h1>
                <p class="dashboard-hero-subtitle">
                    Ringkas untuk pemantauan cepat. <br>
                    Lihat lengkap untuk tabel lebar penuh dengan detail customer, menu, harga, dan keterangan.
                </p>
            </div>
            @if ($viewMode === 'full')
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('adminapp.reports.production', request()->except('view_mode')) }}" class="btn-ghost">
                        Kembali Ringkas
                    </a>
                    <a href="{{ route('adminapp.dashboard') }}" class="btn-ghost">
                        Dashboard Admin
                    </a>
                </div>
            @endif
        </div>
    </section>

    <div class="mt-6 inline-flex items-center gap-1 rounded-xl bg-slate-100 p-1">
        <a href="{{ route('adminapp.reports.production', request()->except('view_mode')) }}"
            class="rounded-lg px-4 py-2 text-sm font-semibold transition {{ $viewMode === 'summary' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-800' }}">
            Ringkas
        </a>
        <a href="{{ route('adminapp.reports.production', array_merge(request()->except('view_mode'), ['view_mode' => 'full'])) }}"
            class="rounded-lg px-4 py-2 text-sm font-semibold transition {{ $viewMode === 'full' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-800' }}">
            Lihat Lengkap
        </a>
    </div>

    <section class="form-shell mt-4">
        <form method="GET" action="{{ route('adminapp.reports.production') }}"
            class="grid grid-cols-1 gap-4 xl:grid-cols-4">
            <div class="space-y-4 xl:col-span-3">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label class="form-label">Dari Tanggal</label>
                        <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control">
                    </div>

                    <div>
                        <label class="form-label">Sampai Tanggal</label>
                        <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control">
                    </div>

                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="">Semua</option>
                            @foreach ($statusLabels as $statusValue => $statusText)
                                <option value="{{ $statusValue }}" @selected($status === $statusValue)>{{ $statusText }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <input type="hidden" name="view_mode" value="{{ $viewMode }}">

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <button class="btn-primary w-full" type="submit">Lihat</button>
                    <a href="{{ route(
                        'adminapp.reports.production',
                        array_filter([
                            'view_mode' => $viewMode === 'full' ? 'full' : null,
                        ]),
                    ) }}"
                        class="btn-ghost w-full text-center">
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </section>

    @include('partials.report-export-actions', [
        'excelUrl' => route('adminapp.reports.production.export.excel', request()->query()),
        'pdfUrl' => route('adminapp.reports.production.export.pdf', request()->query()),
        'caption' =>
            'Export laporan produksi mengikuti filter tanggal, status, dan struktur kolom yang sedang dipakai.',
    ])

    <section class="stats-grid mt-4">
        <article class="stat-card">
            <p class="stat-label">Total SPK</p>
            <p class="stat-value text-brand-600">{{ number_format($spkCount) }}</p>
            <p class="stat-meta">Periode {{ $dateFrom }} s/d {{ $dateTo }}</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">{{ UiLabel::spkStatus('in_process') }}</p>
            <p class="stat-value text-amber-600">{{ number_format($inProcessCount) }}</p>
            <p class="stat-meta">Masih berjalan di produksi</p>
        </article>
        <article class="stat-card">
            <p class="stat-label">{{ UiLabel::spkStatus('completed') }}</p>
            <p class="stat-value text-emerald-600">{{ number_format($completedCount) }}</p>
            <p class="stat-meta">Sudah selesai</p>
        </article>
    </section>

    <section class="table-shell mt-4">
        <div class="table-head flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <span>{{ $viewMode === 'full' ? 'Worksheet Produksi Lengkap' : 'Daftar SPK' }}</span>
            {{-- <span class="text-xs text-slate-500">
      {{ $viewMode === 'full' ? 'Mode lengkap menampilkan seluruh detail per SPK.' : 'Mode ringkas dirapikan agar muat tanpa scroll ke samping.' }}
    </span> --}}
        </div>

        @if ($viewMode === 'full')
            <div class="production-report-wrap">
                <table class="production-report-table">
                    <thead>
                        <tr>
                            <th class="production-col-code">SPK Code</th>
                            <th class="production-col-schedule">Scheduled At</th>
                            <th class="production-col-customer">Customer</th>
                            <th class="production-col-menu">Menu &amp; Jumlah</th>
                            <th class="production-col-price">Harga</th>
                            <th class="production-col-notes">Keterangan</th>
                            <th class="production-col-status">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($spks as $index => $s)
                            @php
                                $reportRow = $reportRows[$index];
                                $statusClass =
                                    $s->status === 'completed'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-amber-100 text-amber-700';
                            @endphp
                            <tr>
                                <td class="font-mono text-xs">{{ $s->spk_code }}</td>
                                <td>{{ $s->scheduled_at ? \Carbon\Carbon::parse($s->scheduled_at)->format('d M Y H:i') : '-' }}
                                </td>
                                <td>
                                    <div class="production-stack">
                                        @forelse ($reportRow['customer_lines'] as $customer)
                                            <div class="production-stack-row">
                                                <span class="production-stack-label">{{ $customer }}</span>
                                            </div>
                                        @empty
                                            <span class="production-stack-note">-</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td>
                                    <div class="production-stack">
                                        @forelse ($reportRow['item_lines'] as $line)
                                            <div class="production-stack-row">
                                                <span class="production-stack-label">{{ $line['product_name'] }}</span>
                                                <span
                                                    class="production-stack-value">x{{ number_format($line['qty'], 0, ',', '.') }}</span>
                                            </div>
                                        @empty
                                            <span class="production-stack-note">-</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td>
                                    <div class="production-stack">
                                        @forelse ($reportRow['item_lines'] as $line)
                                            <div class="production-stack-row">
                                                <span class="production-stack-value">{{ $line['price_text'] }}</span>
                                            </div>
                                        @empty
                                            <span class="production-stack-note">-</span>
                                        @endforelse
                                    </div>
                                    @if ($reportRow['item_lines']->isNotEmpty())
                                        <div class="production-price-total">
                                            Total Rp {{ number_format($reportRow['total_amount'], 0, ',', '.') }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="production-stack">
                                        @forelse ($reportRow['notes_lines'] as $note)
                                            <div class="production-stack-row">
                                                <span class="production-stack-note">{{ $note }}</span>
                                            </div>
                                        @empty
                                            <span class="production-stack-note">-</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td><span
                                        class="status-badge {{ $statusClass }}">{{ UiLabel::spkStatus($s->status) }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-sm text-slate-500">Tidak ada data.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="production-summary-table">
                    <thead>
                        <tr>
                            <th style="width: 13%;">SPK Code</th>
                            <th style="width: 16%;">Scheduled At</th>
                            <th style="width: 20%;">Customer</th>
                            <th style="width: 36%;">Ringkasan</th>
                            <th style="width: 15%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($spks as $index => $s)
                            @php
                                $reportRow = $reportRows[$index];
                                $statusClass =
                                    $s->status === 'completed'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-amber-100 text-amber-700';
                                $summaryMenu = $reportRow['item_lines']
                                    ->take(2)
                                    ->map(
                                        fn($line) => $line['product_name'] .
                                            ' x' .
                                            number_format($line['qty'], 0, ',', '.'),
                                    )
                                    ->implode(', ');
                                if ($reportRow['item_lines']->count() > 2) {
                                    $summaryMenu .= ' +' . ($reportRow['item_lines']->count() - 2) . ' menu';
                                }
                                $summaryNotes = $reportRow['notes_lines']->take(1)->implode(' ');
                                if ($reportRow['notes_lines']->count() > 1) {
                                    $summaryNotes .= ' +' . ($reportRow['notes_lines']->count() - 1) . ' catatan';
                                }
                            @endphp
                            <tr>
                                <td class="font-mono text-xs">{{ $s->spk_code }}</td>
                                <td>{{ $s->scheduled_at ? \Carbon\Carbon::parse($s->scheduled_at)->format('d M Y H:i') : '-' }}
                                </td>
                                <td>{{ $reportRow['customer_summary'] }}</td>
                                <td>
                                    @if ($reportRow['item_lines']->isNotEmpty() || $reportRow['notes_lines']->isNotEmpty())
                                        <details class="production-summary-toggle">
                                            <summary>
                                                Lihat ringkasan
                                                @if ($reportRow['item_lines']->isNotEmpty())
                                                    ({{ $reportRow['item_lines']->count() }} menu)
                                                @endif
                                            </summary>

                                            <div class="production-summary-details production-summary-toggle-body">
                                                <div class="production-summary-line">
                                                    <span class="production-summary-label">Menu</span>
                                                    <span class="production-summary-value">{{ $summaryMenu ?: '-' }}</span>
                                                </div>
                                                <div class="production-summary-line">
                                                    <span class="production-summary-label">Harga</span>
                                                    <span class="production-summary-value">Total Rp
                                                        {{ number_format($reportRow['total_amount'], 0, ',', '.') }}</span>
                                                </div>
                                                <div class="production-summary-line">
                                                    <span class="production-summary-label">Keterangan</span>
                                                    <span class="production-summary-value">{{ $summaryNotes ?: '-' }}</span>
                                                </div>
                                            </div>
                                        </details>
                                    @else
                                        <span class="production-stack-note">-</span>
                                    @endif
                                </td>
                                <td><span
                                        class="status-badge {{ $statusClass }}">{{ UiLabel::spkStatus($s->status) }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-8 text-center text-sm text-slate-500">Tidak ada data.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
