@extends($pageLayout)

@push('styles')
    <style>
        .sales-report-shell {
            color: rgb(15 23 42);
            font-feature-settings: "tnum" 1, "lnum" 1;
            font-variant-numeric: tabular-nums lining-nums;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        .sales-report-hero .dashboard-hero-title {
            font-size: clamp(1.55rem, 1.25rem + 1vw, 2.1rem);
            line-height: 1.1;
            letter-spacing: -0.03em;
            font-weight: 700;
        }

        .sales-report-hero .dashboard-hero-subtitle {
            max-width: 58rem;
            font-size: 0.935rem;
            line-height: 1.65;
            color: rgb(71 85 105);
        }

        .sales-report-tabs a {
            letter-spacing: -0.01em;
        }

        .sales-report-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            margin-bottom: 0.8rem;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgb(100 116 139);
        }

        .sales-report-kicker::before {
            content: "";
            width: 1.5rem;
            height: 1px;
            background: rgb(148 163 184);
        }

        .sales-sheet {
            width: 100%;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 0;
        }

        .sales-sheet th,
        .sales-sheet td {
            padding: 0.65rem 0.72rem;
            font-size: 12.5px;
            line-height: 1.45;
            vertical-align: top;
            border-top: 1px solid rgb(226 232 240);
            border-right: 1px solid rgb(226 232 240);
            word-break: break-word;
            white-space: normal;
        }

        .sales-sheet th:first-child,
        .sales-sheet td:first-child {
            border-left: 1px solid rgb(226 232 240);
        }

        .sales-sheet thead th {
            background: rgb(226 232 240 / 0.96);
            text-align: center;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(15 23 42);
        }

        .sales-sheet-col-no {
            width: 56px;
            text-align: center;
        }

        .sales-sheet-col-name {
            width: 24%;
        }

        .sales-sheet-col-summary {
            width: 42%;
        }

        .sales-sheet-col-qty {
            width: 7%;
            text-align: center;
        }

        .sales-sheet-col-ship {
            width: 8%;
            text-align: right;
        }

        .sales-sheet-col-total {
            width: 9%;
            text-align: right;
        }

        .sales-sheet-date-row td {
            background: rgb(248 250 252);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.01em;
            color: rgb(51 65 85);
        }

        .sales-sheet-subtotal td {
            background: rgb(226 232 240);
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .sales-sheet-share td {
            background: rgb(255 237 213);
            font-weight: 700;
            color: rgb(124 45 18);
        }

        .sales-sheet-total td {
            background: rgb(219 234 254);
            font-weight: 800;
            color: rgb(15 23 42);
            font-size: 13px;
        }

        .sales-sheet-segment-lapak td {
            background: rgb(255 247 237);
            color: rgb(154 52 18);
            font-weight: 700;
        }

        .sales-sheet-segment-nonlapak td {
            background: rgb(248 250 252);
            color: rgb(51 65 85);
            font-weight: 700;
        }

        .sales-stack {
            display: grid;
            gap: 0.42rem;
        }

        .sales-stack-row {
            display: grid;
            gap: 0.14rem;
            border-bottom: 1px dashed rgb(226 232 240);
            padding-bottom: 0.32rem;
        }

        .sales-stack-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .sales-stack-label {
            color: rgb(51 65 85);
            font-size: 12px;
            line-height: 1.45;
            font-weight: 600;
            letter-spacing: -0.01em;
            word-break: break-word;
        }

        .sales-stack-value {
            color: rgb(71 85 105);
            font-weight: 700;
            font-size: 11.5px;
            line-height: 1.35;
            text-align: left;
            white-space: normal;
        }

        .sales-summary-grid {
            display: grid;
            gap: 0.7rem;
        }

        .sales-summary-box {
            border: 1px solid rgb(226 232 240);
            border-radius: 0.9rem;
            background: white;
            padding: 0.7rem 0.8rem;
            box-shadow: inset 0 1px 0 rgb(248 250 252);
        }

        .sales-summary-title {
            margin-bottom: 0.45rem;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(100 116 139);
        }

        .sales-matrix-wrap {
            overflow-x: auto;
            overflow-y: hidden;
            max-width: 100%;
            -webkit-overflow-scrolling: touch;
        }

        .sales-matrix {
            width: max-content;
            min-width: 1380px;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12.5px;
        }

        .sales-matrix th,
        .sales-matrix td {
            padding: 0.65rem 0.72rem;
            font-size: 12.5px;
            line-height: 1.45;
            white-space: nowrap;
            vertical-align: top;
            border-top: 1px solid rgb(226 232 240);
            border-right: 1px solid rgb(226 232 240);
        }

        .sales-matrix thead th {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgb(226 232 240 / 0.96);
            text-align: center;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(15 23 42);
        }

        .sales-matrix th:first-child,
        .sales-matrix td:first-child {
            border-left: 1px solid rgb(226 232 240);
        }

        .sales-matrix-sticky-no,
        .sales-matrix-sticky-name {
            position: sticky;
            z-index: 15;
            background: white;
            box-shadow: inset -1px 0 0 rgb(226 232 240);
        }

        .sales-matrix-sticky-no {
            left: 0;
            min-width: 56px;
            text-align: center;
        }

        .sales-matrix-sticky-name {
            left: 56px;
            min-width: 260px;
            max-width: 260px;
            white-space: normal;
            word-break: break-word;
            line-height: 1.5;
        }

        .sales-meta-lines {
            display: grid;
            gap: 0.2rem;
            margin-top: 0.35rem;
        }

        .sales-meta-line {
            font-size: 11px;
            line-height: 1.35;
            letter-spacing: 0.01em;
            color: rgb(100 116 139);
        }

        .sales-matrix-date-row td {
            background: rgb(248 250 252);
            font-weight: 700;
            letter-spacing: 0.01em;
            color: rgb(51 65 85);
        }

        .sales-matrix-subtotal td {
            background: rgb(226 232 240);
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .sales-matrix-share td {
            background: rgb(255 237 213);
            font-weight: 700;
            color: rgb(124 45 18);
        }

        .sales-matrix-total td {
            background: rgb(219 234 254);
            font-weight: 800;
            color: rgb(15 23 42);
            font-size: 13px;
        }

        .sales-matrix-segment-lapak td {
            background: rgb(255 247 237);
            color: rgb(154 52 18);
            font-weight: 700;
        }

        .sales-matrix-segment-nonlapak td {
            background: rgb(248 250 252);
            color: rgb(51 65 85);
            font-weight: 700;
        }

        .sales-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }

        .sales-legend-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border-radius: 999px;
            border: 1px solid rgb(226 232 240);
            background: white;
            padding: 0.55rem 0.85rem;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: rgb(51 65 85);
        }

        .sales-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            flex-shrink: 0;
        }

        .sales-legend-dot-lapak {
            background: rgb(251 146 60);
        }

        .sales-legend-dot-nonlapak {
            background: rgb(148 163 184);
        }

        .sales-report-customer {
            font-size: 13px;
            line-height: 1.45;
            letter-spacing: -0.01em;
        }

        .sales-sheet .sales-meta-lines {
            gap: 0.15rem;
            margin-top: 0.25rem;
        }

        .sales-sheet .sales-meta-line {
            font-size: 11px;
            line-height: 1.35;
            letter-spacing: 0.01em;
            color: rgb(71 85 105);
        }

        .sales-sheet .sales-sheet-col-no {
            font-weight: 600;
            color: rgb(51 65 85);
        }

        .sales-sheet .sales-sheet-col-qty,
        .sales-sheet .sales-sheet-col-ship,
        .sales-sheet .sales-sheet-col-total {
            white-space: nowrap;
            font-size: 12.5px;
            font-weight: 700;
            color: rgb(15 23 42);
            vertical-align: middle;
        }

        .sales-report-money {
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        .sales-report-micro {
            margin-top: 0.2rem;
            font-size: 10.5px;
            line-height: 1.35;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .sales-report-summary-stack {
            display: grid;
            gap: 0.85rem;
        }

        .sales-report-summary-box {
            border: 1px solid rgb(226 232 240);
            border-radius: 0.9rem;
            background: rgb(248 250 252 / 0.9);
            padding: 0.7rem 0.8rem;
        }

        .sales-report-summary-title {
            margin-bottom: 0.45rem;
            font-size: 0.68rem;
            line-height: 1rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(100 116 139);
        }

        .sales-report-summary-list {
            display: grid;
            gap: 0.35rem;
        }

        .sales-report-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
            padding-bottom: 0.3rem;
            border-bottom: 1px dashed rgb(226 232 240);
        }

        .sales-report-summary-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .sales-report-summary-label {
            min-width: 0;
            font-size: 0.92rem;
            line-height: 1.45;
            font-weight: 600;
            color: rgb(30 41 59);
            word-break: break-word;
        }

        .sales-report-summary-value {
            flex-shrink: 0;
            font-size: 0.85rem;
            line-height: 1.35;
            font-weight: 700;
            color: rgb(71 85 105);
            text-align: right;
            white-space: nowrap;
        }

        .sales-report-group-row td {
            background: rgb(248 250 252);
            font-weight: 700;
            color: rgb(51 65 85);
            letter-spacing: 0.01em;
        }

        .sales-report-subtotal-row td {
            background: rgb(226 232 240);
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .sales-report-share-row td {
            background: rgb(255 237 213);
            font-weight: 700;
            color: rgb(124 45 18);
        }

        .sales-report-total-row td {
            background: rgb(219 234 254);
            font-weight: 800;
            color: rgb(15 23 42);
        }

        .sales-report-segment-lapak td {
            background: rgb(255 247 237);
            color: rgb(154 52 18);
            font-weight: 700;
        }

        .sales-report-segment-nonlapak td {
            background: rgb(248 250 252);
            color: rgb(51 65 85);
            font-weight: 700;
        }

        .sales-section-card {
            border: 1px solid rgb(226 232 240);
            border-radius: 1rem;
            background: white;
            box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
            overflow: hidden;
        }

        .sales-section-card + .sales-section-card {
            margin-top: 1.25rem;
        }

        .sales-section-head {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.95rem 1.1rem;
            border-bottom: 1px solid rgb(226 232 240);
            background: rgb(248 250 252);
        }

        .sales-section-head-title {
            font-size: 13.5px;
            font-weight: 800;
            letter-spacing: -0.01em;
            color: rgb(15 23 42);
        }

        .sales-section-head-desc {
            margin-top: 0.15rem;
            font-size: 11.5px;
            font-weight: 600;
            color: rgb(100 116 139);
        }

        .sales-section-head-meta {
            text-align: right;
            font-size: 12px;
            color: rgb(71 85 105);
            font-weight: 600;
        }

        .sales-section-head-meta strong {
            display: block;
            font-size: 14px;
            color: rgb(15 23 42);
        }

        .sales-section-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12.5px;
            table-layout: fixed;
        }

        .sales-section-table th,
        .sales-section-table td {
            padding: 0.6rem 0.7rem;
            border-top: 1px solid rgb(226 232 240);
            border-right: 1px solid rgb(226 232 240);
            line-height: 1.4;
            vertical-align: top;
            word-break: break-word;
        }

        .sales-section-table th:first-child,
        .sales-section-table td:first-child {
            border-left: 0;
        }

        .sales-section-table th:last-child,
        .sales-section-table td:last-child {
            border-right: 0;
        }

        .sales-section-table thead th {
            background: rgb(241 245 249);
            text-align: center;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(15 23 42);
            border-top: 0;
        }

        .sales-section-col-no {
            width: 44px;
            text-align: center;
        }

        .sales-section-col-name {
            width: 22%;
        }

        .sales-section-col-price {
            text-align: center;
        }

        .sales-section-col-qty,
        .sales-section-col-ship,
        .sales-section-col-total {
            text-align: right;
            white-space: nowrap;
        }

        .sales-section-col-qty {
            width: 7%;
        }

        .sales-section-col-ship {
            width: 10%;
        }

        .sales-section-col-total {
            width: 12%;
        }

        .sales-section-date-row td {
            background: rgb(248 250 252);
            font-weight: 700;
            color: rgb(51 65 85);
            font-size: 11.5px;
            letter-spacing: 0.02em;
        }

        .sales-section-subtotal td {
            background: rgb(226 232 240);
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .sales-section-section-total td {
            background: rgb(254 243 199);
            font-weight: 800;
            color: rgb(120 53 15);
        }

        .sales-section-table .micro {
            display: block;
            margin-top: 0.18rem;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.01em;
            color: inherit;
            opacity: 0.85;
        }

        .sales-section-customer {
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .sales-section-meta-line {
            margin-top: 0.18rem;
            font-size: 10.5px;
            font-weight: 600;
            color: rgb(100 116 139);
        }

        .sales-summary-section {
            border: 1px solid rgb(226 232 240);
            border-radius: 1rem;
            background: white;
            overflow: hidden;
        }

        .sales-summary-section-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13px;
        }

        .sales-summary-section-table th,
        .sales-summary-section-table td {
            padding: 0.7rem 1rem;
            border-top: 1px solid rgb(226 232 240);
        }

        .sales-summary-section-table thead th {
            background: rgb(241 245 249);
            border-top: 0;
            text-align: left;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(15 23 42);
        }

        .sales-summary-section-table td.num {
            text-align: right;
            white-space: nowrap;
            font-weight: 700;
        }

        .sales-summary-section-table tr.total td {
            background: rgb(219 234 254);
            font-weight: 800;
            color: rgb(15 23 42);
            font-size: 14px;
        }

        @media (max-width: 720px) {

            .sales-section-table,
            .sales-summary-section-table {
                font-size: 11.5px;
            }

            .sales-section-table th,
            .sales-section-table td {
                padding: 0.5rem 0.55rem;
            }
        }
    </style>
@endpush

@section('content')
    @php
        $renderItemStack = function (array $map) {
            return collect($map)
                ->filter(fn($qty) => (int) $qty > 0)
                ->map(fn($qty, $label) => ['label' => $label, 'value' => number_format((int) $qty)])
                ->values()
                ->all();
        };

        $renderPriceStack = function (array $map) use ($priceColumns) {
            return collect($priceColumns)
                ->map(function ($price) use ($map) {
                    $amount = (float) ($map[$price['key']] ?? 0);

                    if ($amount <= 0) {
                        return null;
                    }

                    return [
                        'label' => $price['label'],
                        'value' => 'Rp ' . number_format($amount, 0, ',', '.'),
                    ];
                })
                ->filter()
                ->values()
                ->all();
        };

        $segmentRowClass = function (string $key): string {
            return $key === 'lapak' ? 'lapak' : 'nonlapak';
        };

        $totalColumnCount = 2 + count($itemColumns) + 1 + count($priceColumns) + 2;
        $useSectionLayout = $useSectionLayout ?? false;
        $sectionTotalColumnCount = 2 + count($priceColumns) + 3;
        $formatMoney = function ($value) {
            $value = (float) $value;
            return $value > 0 ? 'Rp ' . number_format($value, 0, ',', '.') : '-';
        };
    @endphp

    <div class="sales-report-shell space-y-4">
        <section class="dashboard-hero sales-report-hero">
            <div class="page-toolbar">
                <div>
                    <div class="sales-report-kicker">Sales App Report</div>
                    <h1 class="dashboard-hero-title">Laporan Penjualan</h1>
                    <p class="dashboard-hero-subtitle">
                        Mode ringkas dibuat agar muat satu sheet. <br>
                        Mode untuk melihat komposisi item dan harga secara menyeluruh.
                    </p>
                </div>
                @if ($viewMode === 'full')
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route($reportRouteName, request()->except('view_mode')) }}" class="btn-ghost">
                            Kembali Ringkas
                        </a>
                        <a href="{{ route($dashboardRouteName) }}" class="btn-ghost">
                            {{ $dashboardLabel ?? 'Dashboard' }}
                        </a>
                    </div>
                @endif
            </div>

            <div class="sales-report-tabs mt-6 inline-flex items-center gap-1 rounded-xl bg-slate-100 p-1">
                <a href="{{ route($reportRouteName, request()->except('view_mode')) }}"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition {{ $viewMode === 'summary' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-800' }}">
                    Ringkas
                </a>
                <a href="{{ route($reportRouteName, array_merge(request()->except('view_mode'), ['view_mode' => 'full'])) }}"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition {{ $viewMode === 'full' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-800' }}">
                    Lihat Lengkap
                </a>
            </div>

            <div class="sales-report-tabs mt-4 inline-flex items-center gap-1 rounded-xl bg-slate-100 p-1">
                @foreach ($segmentCards as $segmentCard)
                    <a href="{{ route($reportRouteName, array_merge(request()->except('segment_scope'), ['segment_scope' => $segmentCard['key']])) }}"
                        class="rounded-lg px-4 py-2 text-sm font-semibold transition {{ $segmentScope === $segmentCard['key'] ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-800' }}">
                        {{ $segmentCard['label'] }}
                    </a>
                @endforeach
            </div>

            <form method="GET" action="{{ route($reportRouteName) }}" class="form-grid mt-6">
                <div>
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" name="date_from" value="{{ $dateFrom->toDateString() }}" class="form-control">
                </div>

                <div>
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" name="date_to" value="{{ $dateTo->toDateString() }}" class="form-control">
                </div>

                <input type="hidden" name="view_mode" value="{{ $viewMode }}">
                <input type="hidden" name="segment_scope" value="{{ $segmentScope }}">

                <div class="flex items-end">
                    <button type="submit" class="btn-primary w-full">Terapkan Filter</button>
                </div>

                <div class="flex items-end">
                    <a href="{{ route(
                        $reportRouteName,
                        array_filter([
                            'view_mode' => $viewMode === 'full' ? 'full' : null,
                            'segment_scope' => $segmentScope !== 'all' ? $segmentScope : null,
                        ]),
                    ) }}"
                        class="btn-ghost w-full text-center">Reset</a>
                </div>
            </form>
        </section>

        @include('partials.report-export-actions', [
            'excelUrl' => route($reportExcelRouteName, request()->query()),
            'pdfUrl' => route($reportPdfRouteName, request()->query()),
            'caption' => 'Export laporan penjualan mengikuti filter yang sedang dipilih.',
        ])

        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($segmentCards as $segmentCard)
                <article class="stat-card {{ $segmentScope === $segmentCard['key'] ? 'ring-2 ring-brand-100' : '' }}">
                    <p class="stat-label">{{ $segmentCard['label'] }}</p>
                    <p
                        class="stat-value {{ $segmentCard['key'] === 'lapak' ? 'text-amber-700' : ($segmentCard['key'] === 'non_lapak' ? 'text-slate-900' : 'text-brand-600') }}">
                        Rp {{ number_format((float) ($segmentCard['total_amount'] ?? 0), 0, ',', '.') }}
                    </p>
                    <p class="stat-meta">
                        {{ number_format((int) ($segmentCard['total_orders'] ?? 0)) }} order |
                        {{ number_format((int) ($segmentCard['total_qty'] ?? 0)) }} qty
                        @if ($segmentCard['share_percent'] !== null)
                            | {{ number_format((float) $segmentCard['share_percent'], 1, ',', '.') }}% dari total
                        @endif
                    </p>
                </article>
            @endforeach
        </section>

        <section class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="stat-card">
                <p class="stat-label">Periode</p>
                <p class="stat-value text-slate-900">{{ $dateFrom->format('d M') }} - {{ $dateTo->format('d M Y') }}</p>
                <p class="stat-meta">Berdasarkan tanggal penjualan (Sales Actual submitted) |
                    {{ collect($segmentCards)->firstWhere('key', $segmentScope)['label'] ?? 'Semua' }}</p>
            </article>
            <article class="stat-card">
                <p class="stat-label">Jumlah Order Aktif</p>
                <p class="stat-value text-brand-600">{{ number_format((int) ($salesGrandTotals['total_orders'] ?? 0)) }}
                </p>
                <p class="stat-meta">Sesuai tab/filter yang dipilih</p>
            </article>
            <article class="stat-card">
                <p class="stat-label">Total Qty Aktif</p>
                <p class="stat-value text-slate-900">{{ number_format((int) ($salesGrandTotals['total_qty'] ?? 0)) }}</p>
                <p class="stat-meta">Akumulasi item terjual pada segmen aktif</p>
            </article>
            <article class="stat-card">
                <p class="stat-label">Total Penjualan Aktif</p>
                <p class="stat-value text-emerald-700">Rp
                    {{ number_format((float) ($salesGrandTotals['grand_total'] ?? 0), 0, ',', '.') }}</p>
                <p class="stat-meta">Murni item terjual, di luar ongkos kirim</p>
            </article>
            <article class="stat-card">
                <p class="stat-label">Total Ongkos Kirim</p>
                <p class="stat-value text-slate-900">Rp
                    {{ number_format((float) ($salesGrandTotals['shipping_total'] ?? 0), 0, ',', '.') }}</p>
                <p class="stat-meta">Ditampilkan terpisah, tidak masuk total penjualan</p>
            </article>
        </section>

        @if ($segmentScope === 'all')
            <section class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                <div class="sales-legend">
                    {{-- <span class="sales-legend-chip">
          <span class="sales-legend-dot sales-legend-dot-lapak"></span>
          Lapak
        </span>
        <span class="sales-legend-chip">
          <span class="sales-legend-dot sales-legend-dot-nonlapak"></span>
          Retail
        </span> --}}
                    <span class="text-xs font-medium text-slate-600">
                        Setiap tanggal menampilkan subtotal terpisah. <br>
                        Angka persen pertama = kontribusi ke total periode, persen kedua = kontribusi ke subtotal hari itu.
                    </span>
                </div>
            </section>
        @endif

        @if ($viewMode === 'full' && $useSectionLayout)
            @php
                $visibleSections = collect($salesSections ?? [])
                    ->filter(fn($s) => !empty($s['groups']))
                    ->values()
                    ->all();
            @endphp
            <div class="mt-6 space-y-4">
                @forelse ($visibleSections as $section)
                    <section class="sales-section-card">
                        <header class="sales-section-head">
                            <div>
                                <div class="sales-section-head-title">{{ $section['label'] }}</div>
                                <div class="sales-section-head-desc">{{ $section['description'] }}</div>
                            </div>
                            <div class="sales-section-head-meta">
                                <strong>Rp {{ number_format((float) $section['subtotal_amount'], 0, ',', '.') }}</strong>
                                {{ number_format((int) $section['rows_count']) }} order |
                                {{ number_format((int) $section['total_qty']) }} pack
                                @if ($section['period_share_percent'] !== null)
                                    | {{ number_format((float) $section['period_share_percent'], 1, ',', '.') }}% dari total
                                @endif
                            </div>
                        </header>

                        <div class="overflow-x-auto">
                            <table class="sales-section-table">
                                <colgroup>
                                    <col class="sales-section-col-no">
                                    <col class="sales-section-col-name">
                                    @foreach ($priceColumns as $priceColumn)
                                        <col class="sales-section-col-price">
                                    @endforeach
                                    <col class="sales-section-col-qty">
                                    <col class="sales-section-col-ship">
                                    <col class="sales-section-col-total">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>No.</th>
                                        <th class="text-left">Nama</th>
                                        @foreach ($priceColumns as $priceColumn)
                                            <th>{{ $priceColumn['label'] }}</th>
                                        @endforeach
                                        <th>Jumlah Pack</th>
                                        <th>Ongkir</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @if (empty($section['groups']))
                                        <tr>
                                            <td colspan="{{ $sectionTotalColumnCount }}"
                                                class="px-4 py-6 text-center text-sm text-slate-500">
                                                Belum ada data penjualan untuk segmen ini.
                                            </td>
                                        </tr>
                                    @else
                                        @php $rowNumber = 1; @endphp
                                        @foreach ($section['groups'] as $group)
                                            <tr class="sales-section-date-row">
                                                <td colspan="{{ $sectionTotalColumnCount }}">
                                                    Tanggal Penjualan: {{ $group['date_label'] }}
                                                </td>
                                            </tr>

                                            @foreach ($group['rows'] as $row)
                                                <tr>
                                                    <td class="text-center">{{ $rowNumber++ }}</td>
                                                    <td>
                                                        <div class="sales-section-customer">
                                                            {{ $row['customer_label'] }}</div>
                                                        @foreach (($row['order_meta_lines'] ?? []) as $metaLine)
                                                            <div class="sales-section-meta-line">{{ $metaLine }}</div>
                                                        @endforeach
                                                    </td>
                                                    @foreach ($priceColumns as $priceColumn)
                                                        @php $qty = (int) ($row['price_qty_map'][$priceColumn['key']] ?? 0); @endphp
                                                        <td class="text-center">
                                                            {{ $qty > 0 ? number_format($qty) : '-' }}</td>
                                                    @endforeach
                                                    <td>{{ number_format((int) ($row['total_qty'] ?? 0)) }}</td>
                                                    <td>{{ $formatMoney($row['shipping_cost'] ?? 0) }}</td>
                                                    <td>Rp
                                                        {{ number_format((float) ($row['total_amount'] ?? 0), 0, ',', '.') }}
                                                    </td>
                                                </tr>
                                            @endforeach

                                            <tr class="sales-section-subtotal">
                                                <td colspan="2" class="text-center">SUB TOTAL
                                                    {{ strtoupper($group['date_label']) }}</td>
                                                @foreach ($priceColumns as $priceColumn)
                                                    @php $qty = (int) ($group['price_qty_totals'][$priceColumn['key']] ?? 0); @endphp
                                                    <td class="text-center">
                                                        {{ $qty > 0 ? number_format($qty) : '-' }}</td>
                                                @endforeach
                                                <td>{{ number_format((int) ($group['total_qty'] ?? 0)) }}</td>
                                                <td>{{ $formatMoney($group['shipping_total'] ?? 0) }}</td>
                                                <td>
                                                    Rp {{ number_format((float) ($group['subtotal_amount'] ?? 0), 0, ',', '.') }}
                                                    <span class="micro">
                                                        {{ $group['period_share_percent'] !== null ? number_format((float) $group['period_share_percent'], 1, ',', '.') . '% dari periode' : '-' }}
                                                    </span>
                                                    <span class="micro">
                                                        {{ $group['section_share_percent'] !== null ? number_format((float) $group['section_share_percent'], 1, ',', '.') . '% dari ' . $section['label'] : '-' }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach

                                        <tr class="sales-section-section-total">
                                            <td colspan="2" class="text-center">SUB TOTAL
                                                {{ strtoupper($section['label']) }}</td>
                                            @foreach ($priceColumns as $priceColumn)
                                                @php $qty = (int) ($section['price_qty_totals'][$priceColumn['key']] ?? 0); @endphp
                                                <td class="text-center">
                                                    {{ $qty > 0 ? number_format($qty) : '-' }}</td>
                                            @endforeach
                                            <td>{{ number_format((int) ($section['total_qty'] ?? 0)) }}</td>
                                            <td>{{ $formatMoney($section['shipping_total'] ?? 0) }}</td>
                                            <td>
                                                Rp {{ number_format((float) ($section['subtotal_amount'] ?? 0), 0, ',', '.') }}
                                                <span class="micro">
                                                    {{ $section['period_share_percent'] !== null ? number_format((float) $section['period_share_percent'], 1, ',', '.') . '% dari total periode' : '-' }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    </section>
                @empty
                    <section class="sales-section-card">
                        <div class="px-4 py-8 text-center text-sm text-slate-500">
                            Belum ada data penjualan completed pada periode ini.
                        </div>
                    </section>
                @endforelse

                @if (!empty($visibleSections))
                    <section class="sales-summary-section">
                        <table class="sales-summary-section-table">
                            <thead>
                                <tr>
                                    <th>Ringkasan Total</th>
                                    <th class="num" style="text-align: right;">Order</th>
                                    <th class="num" style="text-align: right;">Pack</th>
                                    <th class="num" style="text-align: right;">Ongkir</th>
                                    <th class="num" style="text-align: right;">Total</th>
                                    <th class="num" style="text-align: right;">% Periode</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($visibleSections as $section)
                                    <tr>
                                        <td>Total {{ $section['label'] }}</td>
                                        <td class="num">{{ number_format((int) $section['rows_count']) }}</td>
                                        <td class="num">{{ number_format((int) $section['total_qty']) }}</td>
                                        <td class="num">{{ $formatMoney($section['shipping_total'] ?? 0) }}</td>
                                        <td class="num">Rp
                                            {{ number_format((float) $section['subtotal_amount'], 0, ',', '.') }}</td>
                                        <td class="num">
                                            {{ $section['period_share_percent'] !== null ? number_format((float) $section['period_share_percent'], 1, ',', '.') . '%' : '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                                <tr class="total">
                                    <td>Total Penjualan</td>
                                    <td class="num">
                                        {{ number_format((int) ($salesGrandTotals['total_orders'] ?? 0)) }}</td>
                                    <td class="num">
                                        {{ number_format((int) ($salesGrandTotals['total_qty'] ?? 0)) }}</td>
                                    <td class="num">{{ $formatMoney($salesGrandTotals['shipping_total'] ?? 0) }}</td>
                                    <td class="num">Rp
                                        {{ number_format((float) ($salesGrandTotals['grand_total'] ?? 0), 0, ',', '.') }}
                                    </td>
                                    <td class="num">100%</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                @endif
            </div>
        @elseif ($viewMode === 'full')
            <section class="table-shell mt-6">
                <div class="table-head flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <span>Worksheet Penjualan Lengkap</span>
                </div>

                <div class="sales-matrix-wrap">
                    <table class="sales-matrix">
                        <thead>
                            <tr>
                                <th class="sales-matrix-sticky-no">No.</th>
                                <th class="sales-matrix-sticky-name">Nama</th>
                                @foreach ($itemColumns as $itemColumn)
                                    <th>{{ $itemColumn }}</th>
                                @endforeach
                                <th>Jumlah</th>
                                @foreach ($priceColumns as $priceColumn)
                                    <th>{{ $priceColumn['label'] }}</th>
                                @endforeach
                                <th>Ongkir</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $rowNumber = 1; @endphp

                            @forelse ($salesGroups as $group)
                                <tr class="sales-matrix-date-row">
                                    <td colspan="{{ $totalColumnCount }}">Tanggal Penjualan: {{ $group['date_label'] }}
                                    </td>
                                </tr>

                                @foreach ($group['rows'] as $row)
                                    <tr>
                                        <td class="sales-matrix-sticky-no">{{ $rowNumber++ }}</td>
                                        <td class="sales-matrix-sticky-name">
                                            <div class="sales-report-customer font-semibold text-slate-900">
                                                {{ $row['customer_label'] }}</div>
                                            @if (!empty($row['order_meta_lines']))
                                                <div class="sales-meta-lines">
                                                    @foreach ($row['order_meta_lines'] as $metaLine)
                                                        <div class="sales-meta-line">{{ $metaLine }}</div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        @foreach ($itemColumns as $itemColumn)
                                            <td class="text-center">
                                                {{ (int) ($row['item_qty_map'][$itemColumn] ?? 0) ?: '-' }}</td>
                                        @endforeach
                                        <td class="text-center font-semibold text-slate-900">
                                            {{ number_format((int) ($row['total_qty'] ?? 0)) }}</td>
                                        @foreach ($priceColumns as $priceColumn)
                                            @php $priceValue = (float) ($row['price_amount_map'][$priceColumn['key']] ?? 0); @endphp
                                            <td class="text-right">
                                                {{ $priceValue > 0 ? 'Rp ' . number_format($priceValue, 0, ',', '.') : '-' }}
                                            </td>
                                        @endforeach
                                        <td class="text-right">
                                            {{ (float) ($row['shipping_cost'] ?? 0) > 0 ? 'Rp ' . number_format((float) $row['shipping_cost'], 0, ',', '.') : '-' }}
                                        </td>
                                        <td class="text-right font-semibold text-slate-900">Rp
                                            {{ number_format((float) ($row['total_amount'] ?? 0), 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach

                                @if ($segmentScope === 'all')
                                    @foreach ($group['segment_breakdown'] ?? [] as $segment)
                                        <tr class="sales-matrix-segment-{{ $segmentRowClass($segment['key']) }}">
                                            <td colspan="2" class="text-center">SUB TOTAL
                                                {{ strtoupper($segment['label']) }}</td>
                                            @foreach ($itemColumns as $itemColumn)
                                                <td class="text-center">
                                                    {{ number_format((int) ($segment['item_totals'][$itemColumn] ?? 0)) ?: '-' }}
                                                </td>
                                            @endforeach
                                            <td class="text-center">{{ number_format((int) ($segment['total_qty'] ?? 0)) }}
                                            </td>
                                            @foreach ($priceColumns as $priceColumn)
                                                <td class="text-right">
                                                    {{ (float) ($segment['price_totals'][$priceColumn['key']] ?? 0) > 0 ? 'Rp ' . number_format((float) $segment['price_totals'][$priceColumn['key']], 0, ',', '.') : '-' }}
                                                </td>
                                            @endforeach
                                            <td class="text-right">
                                                {{ (float) ($segment['shipping_total'] ?? 0) > 0 ? 'Rp ' . number_format((float) $segment['shipping_total'], 0, ',', '.') : '-' }}
                                            </td>
                                            <td class="text-right">
                                                <div class="sales-report-money">Rp
                                                    {{ number_format((float) ($segment['subtotal_amount'] ?? 0), 0, ',', '.') }}
                                                </div>
                                                <div class="sales-report-micro">
                                                    {{ $segment['period_share_percent'] !== null ? number_format((float) $segment['period_share_percent'], 1, ',', '.') . '% dari periode' : '-' }}
                                                </div>
                                                <div class="sales-report-micro mt-0">
                                                    {{ $segment['group_share_percent'] !== null ? number_format((float) $segment['group_share_percent'], 1, ',', '.') . '% dari hari ini' : '-' }}
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif

                                <tr class="sales-matrix-subtotal">
                                    <td colspan="2" class="text-center">SUB TOTAL PENJUALAN</td>
                                    @foreach ($itemColumns as $itemColumn)
                                        <td class="text-center">
                                            {{ number_format((int) ($group['item_totals'][$itemColumn] ?? 0)) }}</td>
                                    @endforeach
                                    <td class="text-center">{{ number_format((int) ($group['total_qty'] ?? 0)) }}</td>
                                    @foreach ($priceColumns as $priceColumn)
                                        <td class="text-right">
                                            {{ (float) ($group['price_totals'][$priceColumn['key']] ?? 0) > 0 ? 'Rp ' . number_format((float) $group['price_totals'][$priceColumn['key']], 0, ',', '.') : '-' }}
                                        </td>
                                    @endforeach
                                    <td class="text-right">
                                        {{ (float) ($group['shipping_total'] ?? 0) > 0 ? 'Rp ' . number_format((float) $group['shipping_total'], 0, ',', '.') : '-' }}
                                    </td>
                                    <td class="text-right">Rp
                                        {{ number_format((float) ($group['subtotal_amount'] ?? 0), 0, ',', '.') }}</td>
                                </tr>

                                <tr class="sales-matrix-share">
                                    <td colspan="{{ $totalColumnCount - 1 }}" class="text-center">SUB TOTAL PROSENTASE
                                    </td>
                                    <td class="text-right">
                                        {{ $group['percentage'] !== null ? number_format((float) $group['percentage'], 0, ',', '.') . '%' : '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $totalColumnCount }}" class="px-4 py-8 text-center text-slate-500">
                                        Belum ada data penjualan completed pada periode ini.
                                    </td>
                                </tr>
                            @endforelse

                            @if (!empty($salesGroups))
                                @if ($segmentScope === 'all')
                                    @foreach ($salesGrandTotals['segment_breakdown'] ?? [] as $segment)
                                        <tr class="sales-matrix-segment-{{ $segmentRowClass($segment['key']) }}">
                                            <td colspan="2" class="text-center">TOTAL
                                                {{ strtoupper($segment['label']) }}</td>
                                            @foreach ($itemColumns as $itemColumn)
                                                <td class="text-center">
                                                    {{ number_format((int) ($segment['item_totals'][$itemColumn] ?? 0)) ?: '-' }}
                                                </td>
                                            @endforeach
                                            <td class="text-center">
                                                {{ number_format((int) ($segment['total_qty'] ?? 0)) }}</td>
                                            @foreach ($priceColumns as $priceColumn)
                                                <td class="text-right">
                                                    {{ (float) ($segment['price_totals'][$priceColumn['key']] ?? 0) > 0 ? 'Rp ' . number_format((float) $segment['price_totals'][$priceColumn['key']], 0, ',', '.') : '-' }}
                                                </td>
                                            @endforeach
                                            <td class="text-right">
                                                {{ (float) ($segment['shipping_total'] ?? 0) > 0 ? 'Rp ' . number_format((float) $segment['shipping_total'], 0, ',', '.') : '-' }}
                                            </td>
                                            <td class="text-right">
                                                <div class="sales-report-money">Rp
                                                    {{ number_format((float) ($segment['subtotal_amount'] ?? 0), 0, ',', '.') }}
                                                </div>
                                                <div class="sales-report-micro">
                                                    {{ $segment['period_share_percent'] !== null ? number_format((float) $segment['period_share_percent'], 1, ',', '.') . '% dari periode' : '-' }}
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif

                                <tr class="sales-matrix-total">
                                    <td colspan="2" class="text-center">TOTAL PENJUALAN</td>
                                    @foreach ($itemColumns as $itemColumn)
                                        <td class="text-center">
                                            {{ number_format((int) ($salesGrandTotals['item_totals'][$itemColumn] ?? 0)) }}
                                        </td>
                                    @endforeach
                                    <td class="text-center">
                                        {{ number_format((int) ($salesGrandTotals['total_qty'] ?? 0)) }}</td>
                                    @foreach ($priceColumns as $priceColumn)
                                        <td class="text-right">
                                            {{ (float) ($salesGrandTotals['price_totals'][$priceColumn['key']] ?? 0) > 0 ? 'Rp ' . number_format((float) $salesGrandTotals['price_totals'][$priceColumn['key']], 0, ',', '.') : '-' }}
                                        </td>
                                    @endforeach
                                    <td class="text-right">
                                        {{ (float) ($salesGrandTotals['shipping_total'] ?? 0) > 0 ? 'Rp ' . number_format((float) $salesGrandTotals['shipping_total'], 0, ',', '.') : '-' }}
                                    </td>
                                    <td class="text-right">Rp
                                        {{ number_format((float) ($salesGrandTotals['grand_total'] ?? 0), 0, ',', '.') }}
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            <section class="table-shell sales-table sales-responsive-table mt-6">
                <div class="table-head flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <span>Worksheet Penjualan Ringkas</span>
                </div>

                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Nama</th>
                                <th>Ringkasan Order</th>
                                <th>Jumlah</th>
                                <th>Ongkir</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $rowNumber = 1; @endphp

                            @forelse ($salesGroups as $group)
                                <tr class="sales-report-group-row">
                                    <td colspan="6">Tanggal Penjualan: {{ $group['date_label'] }}</td>
                                </tr>

                                @foreach ($group['rows'] as $row)
                                    @php
                                        $itemStack = $renderItemStack($row['item_qty_map'] ?? []);
                                        $priceStack = $renderPriceStack($row['price_amount_map'] ?? []);
                                    @endphp
                                    <tr>
                                        <td class="number-cell" data-label="No.">{{ $rowNumber++ }}</td>
                                        <td data-label="Nama">
                                            <div class="sales-report-customer font-semibold text-slate-900">
                                                {{ $row['customer_label'] }}</div>
                                            @if (!empty($row['order_meta_lines']))
                                                <div class="sales-meta-lines">
                                                    @foreach ($row['order_meta_lines'] as $metaLine)
                                                        <div class="sales-meta-line">{{ $metaLine }}</div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td class="item-cell" data-label="Ringkasan Order">
                                            <div class="sales-report-summary-stack">
                                                <div class="sales-report-summary-box">
                                                    <div class="sales-report-summary-title">Komposisi</div>
                                                    @if (!empty($itemStack))
                                                        <div class="sales-report-summary-list">
                                                            @foreach ($itemStack as $item)
                                                                <div class="sales-report-summary-row">
                                                                    <span
                                                                        class="sales-report-summary-label">{{ $item['label'] }}</span>
                                                                    <span
                                                                        class="sales-report-summary-value">{{ $item['value'] }}</span>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <span class="text-slate-400">-</span>
                                                    @endif
                                                </div>

                                                <div class="sales-report-summary-box">
                                                    <div class="sales-report-summary-title">Harga</div>
                                                    @if (!empty($priceStack))
                                                        <div class="sales-report-summary-list">
                                                            @foreach ($priceStack as $price)
                                                                <div class="sales-report-summary-row">
                                                                    <span
                                                                        class="sales-report-summary-label">{{ $price['label'] }}</span>
                                                                    <span
                                                                        class="sales-report-summary-value">{{ $price['value'] }}</span>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <span class="text-slate-400">-</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td class="number-cell font-semibold text-slate-900" data-label="Jumlah">
                                            {{ number_format((int) ($row['total_qty'] ?? 0)) }}</td>
                                        <td class="number-cell" data-label="Ongkir">
                                            {{ (float) ($row['shipping_cost'] ?? 0) > 0 ? 'Rp ' . number_format((float) $row['shipping_cost'], 0, ',', '.') : '-' }}
                                        </td>
                                        <td class="number-cell font-semibold text-slate-900" data-label="Total">Rp
                                            {{ number_format((float) ($row['total_amount'] ?? 0), 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach

                                @if ($segmentScope === 'all')
                                    @foreach ($group['segment_breakdown'] ?? [] as $segment)
                                        @php
                                            $segmentItemStack = $renderItemStack($segment['item_totals'] ?? []);
                                            $segmentPriceStack = $renderPriceStack($segment['price_totals'] ?? []);
                                        @endphp
                                        <tr class="sales-report-segment-{{ $segmentRowClass($segment['key']) }}">
                                            <td colspan="2" class="text-center">SUB TOTAL
                                                {{ strtoupper($segment['label']) }}</td>
                                            <td class="item-cell" data-label="Ringkasan Order">
                                                <div class="sales-report-summary-stack">
                                                    <div class="sales-report-summary-box">
                                                        <div class="sales-report-summary-title">Komposisi</div>
                                                        @if (!empty($segmentItemStack))
                                                            <div class="sales-report-summary-list">
                                                                @foreach ($segmentItemStack as $item)
                                                                    <div class="sales-report-summary-row">
                                                                        <span
                                                                            class="sales-report-summary-label">{{ $item['label'] }}</span>
                                                                        <span
                                                                            class="sales-report-summary-value">{{ $item['value'] }}</span>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @else
                                                            -
                                                        @endif
                                                    </div>
                                                    <div class="sales-report-summary-box">
                                                        <div class="sales-report-summary-title">Harga</div>
                                                        @if (!empty($segmentPriceStack))
                                                            <div class="sales-report-summary-list">
                                                                @foreach ($segmentPriceStack as $price)
                                                                    <div class="sales-report-summary-row">
                                                                        <span
                                                                            class="sales-report-summary-label">{{ $price['label'] }}</span>
                                                                        <span
                                                                            class="sales-report-summary-value">{{ $price['value'] }}</span>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @else
                                                            -
                                                        @endif
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="number-cell" data-label="Jumlah">
                                                {{ number_format((int) ($segment['total_qty'] ?? 0)) }}</td>
                                            <td class="number-cell" data-label="Ongkir">
                                                {{ (float) ($segment['shipping_total'] ?? 0) > 0 ? 'Rp ' . number_format((float) $segment['shipping_total'], 0, ',', '.') : '-' }}
                                            </td>
                                            <td class="number-cell" data-label="Total">
                                                <div>Rp
                                                    {{ number_format((float) ($segment['subtotal_amount'] ?? 0), 0, ',', '.') }}
                                                </div>
                                                <div class="mt-1 text-[10px] font-semibold">
                                                    {{ $segment['period_share_percent'] !== null ? number_format((float) $segment['period_share_percent'], 1, ',', '.') . '% dari periode' : '-' }}
                                                </div>
                                                <div class="text-[10px] font-semibold">
                                                    {{ $segment['group_share_percent'] !== null ? number_format((float) $segment['group_share_percent'], 1, ',', '.') . '% dari hari ini' : '-' }}
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif

                                @php
                                    $groupItemStack = $renderItemStack($group['item_totals'] ?? []);
                                    $groupPriceStack = $renderPriceStack($group['price_totals'] ?? []);
                                @endphp
                                <tr class="sales-report-subtotal-row">
                                    <td colspan="2" class="text-center">SUB TOTAL PENJUALAN</td>
                                    <td class="item-cell" data-label="Ringkasan Order">
                                        <div class="sales-report-summary-stack">
                                            <div class="sales-report-summary-box">
                                                <div class="sales-report-summary-title">Komposisi</div>
                                                @if (!empty($groupItemStack))
                                                    <div class="sales-report-summary-list">
                                                        @foreach ($groupItemStack as $item)
                                                            <div class="sales-report-summary-row">
                                                                <span
                                                                    class="sales-report-summary-label">{{ $item['label'] }}</span>
                                                                <span
                                                                    class="sales-report-summary-value">{{ $item['value'] }}</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    -
                                                @endif
                                            </div>
                                            <div class="sales-report-summary-box">
                                                <div class="sales-report-summary-title">Harga</div>
                                                @if (!empty($groupPriceStack))
                                                    <div class="sales-report-summary-list">
                                                        @foreach ($groupPriceStack as $price)
                                                            <div class="sales-report-summary-row">
                                                                <span
                                                                    class="sales-report-summary-label">{{ $price['label'] }}</span>
                                                                <span
                                                                    class="sales-report-summary-value">{{ $price['value'] }}</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    -
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="number-cell" data-label="Jumlah">
                                        {{ number_format((int) ($group['total_qty'] ?? 0)) }}</td>
                                    <td class="number-cell" data-label="Ongkir">
                                        {{ (float) ($group['shipping_total'] ?? 0) > 0 ? 'Rp ' . number_format((float) $group['shipping_total'], 0, ',', '.') : '-' }}
                                    </td>
                                    <td class="number-cell" data-label="Total">Rp
                                        {{ number_format((float) ($group['subtotal_amount'] ?? 0), 0, ',', '.') }}</td>
                                </tr>

                                <tr class="sales-report-share-row">
                                    <td colspan="5" class="text-center">SUB TOTAL PROSENTASE</td>
                                    <td class="number-cell" data-label="Total">
                                        {{ $group['percentage'] !== null ? number_format((float) $group['percentage'], 0, ',', '.') . '%' : '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr class="empty-row">
                                    <td colspan="6" class="full-cell py-8 text-center text-sm text-slate-500">
                                        Belum ada data penjualan completed pada periode ini.
                                    </td>
                                </tr>
                            @endforelse

                            @if (!empty($salesGroups))
                                @if ($segmentScope === 'all')
                                    @foreach ($salesGrandTotals['segment_breakdown'] ?? [] as $segment)
                                        @php
                                            $segmentItemStack = $renderItemStack($segment['item_totals'] ?? []);
                                            $segmentPriceStack = $renderPriceStack($segment['price_totals'] ?? []);
                                        @endphp
                                        <tr class="sales-report-segment-{{ $segmentRowClass($segment['key']) }}">
                                            <td colspan="2" class="text-center">TOTAL
                                                {{ strtoupper($segment['label']) }}</td>
                                            <td class="item-cell" data-label="Ringkasan Order">
                                                <div class="sales-report-summary-stack">
                                                    <div class="sales-report-summary-box">
                                                        <div class="sales-report-summary-title">Komposisi</div>
                                                        @if (!empty($segmentItemStack))
                                                            <div class="sales-report-summary-list">
                                                                @foreach ($segmentItemStack as $item)
                                                                    <div class="sales-report-summary-row">
                                                                        <span
                                                                            class="sales-report-summary-label">{{ $item['label'] }}</span>
                                                                        <span
                                                                            class="sales-report-summary-value">{{ $item['value'] }}</span>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @else
                                                            -
                                                        @endif
                                                    </div>
                                                    <div class="sales-report-summary-box">
                                                        <div class="sales-report-summary-title">Harga</div>
                                                        @if (!empty($segmentPriceStack))
                                                            <div class="sales-report-summary-list">
                                                                @foreach ($segmentPriceStack as $price)
                                                                    <div class="sales-report-summary-row">
                                                                        <span
                                                                            class="sales-report-summary-label">{{ $price['label'] }}</span>
                                                                        <span
                                                                            class="sales-report-summary-value">{{ $price['value'] }}</span>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @else
                                                            -
                                                        @endif
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="number-cell" data-label="Jumlah">
                                                {{ number_format((int) ($segment['total_qty'] ?? 0)) }}</td>
                                            <td class="number-cell" data-label="Ongkir">
                                                {{ (float) ($segment['shipping_total'] ?? 0) > 0 ? 'Rp ' . number_format((float) $segment['shipping_total'], 0, ',', '.') : '-' }}
                                            </td>
                                            <td class="number-cell" data-label="Total">
                                                <div>Rp
                                                    {{ number_format((float) ($segment['subtotal_amount'] ?? 0), 0, ',', '.') }}
                                                </div>
                                                <div class="mt-1 text-[10px] font-semibold">
                                                    {{ $segment['period_share_percent'] !== null ? number_format((float) $segment['period_share_percent'], 1, ',', '.') . '% dari periode' : '-' }}
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif

                                @php
                                    $grandItemStack = $renderItemStack($salesGrandTotals['item_totals'] ?? []);
                                    $grandPriceStack = $renderPriceStack($salesGrandTotals['price_totals'] ?? []);
                                @endphp
                                <tr class="sales-report-total-row">
                                    <td colspan="2" class="text-center">TOTAL PENJUALAN</td>
                                    <td class="item-cell" data-label="Ringkasan Order">
                                        <div class="sales-report-summary-stack">
                                            <div class="sales-report-summary-box">
                                                <div class="sales-report-summary-title">Komposisi</div>
                                                @if (!empty($grandItemStack))
                                                    <div class="sales-report-summary-list">
                                                        @foreach ($grandItemStack as $item)
                                                            <div class="sales-report-summary-row">
                                                                <span
                                                                    class="sales-report-summary-label">{{ $item['label'] }}</span>
                                                                <span
                                                                    class="sales-report-summary-value">{{ $item['value'] }}</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    -
                                                @endif
                                            </div>
                                            <div class="sales-report-summary-box">
                                                <div class="sales-report-summary-title">Harga</div>
                                                @if (!empty($grandPriceStack))
                                                    <div class="sales-report-summary-list">
                                                        @foreach ($grandPriceStack as $price)
                                                            <div class="sales-report-summary-row">
                                                                <span
                                                                    class="sales-report-summary-label">{{ $price['label'] }}</span>
                                                                <span
                                                                    class="sales-report-summary-value">{{ $price['value'] }}</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    -
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="number-cell" data-label="Jumlah">
                                        {{ number_format((int) ($salesGrandTotals['total_qty'] ?? 0)) }}</td>
                                    <td class="number-cell" data-label="Ongkir">
                                        {{ (float) ($salesGrandTotals['shipping_total'] ?? 0) > 0 ? 'Rp ' . number_format((float) $salesGrandTotals['shipping_total'], 0, ',', '.') : '-' }}
                                    </td>
                                    <td class="number-cell" data-label="Total">Rp
                                        {{ number_format((float) ($salesGrandTotals['grand_total'] ?? 0), 0, ',', '.') }}
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
