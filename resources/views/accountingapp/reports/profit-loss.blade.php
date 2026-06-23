@extends($pageLayout)

@push('styles')
  <style>
    .final-pl-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      font-size: 13px;
    }

    .final-pl-table td {
      padding: 0.65rem 0.85rem;
      border-top: 1px solid rgb(226 232 240);
      vertical-align: top;
    }

    .final-pl-table tr:first-child td {
      border-top: 0;
    }

    .final-pl-table td.label-cell {
      color: rgb(15 23 42);
      font-weight: 600;
    }

    .final-pl-table td.value-cell {
      text-align: right;
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
    }

    .final-pl-table tr.section-row td {
      background: rgb(241 245 249);
      font-weight: 800;
      font-size: 12px;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: rgb(15 23 42);
    }

    .final-pl-table tr.subtotal-row td {
      background: rgb(226 232 240);
      font-weight: 700;
      color: rgb(15 23 42);
    }

    .final-pl-table tr.major-row td {
      background: rgb(219 234 254);
      font-weight: 800;
      color: rgb(15 23 42);
      font-size: 13.5px;
    }

    .final-pl-table tr.profit-row td {
      background: rgb(220 252 231);
      color: rgb(20 83 45);
      font-weight: 800;
    }

    .final-pl-table tr.profit-row.negative td {
      background: rgb(254 226 226);
      color: rgb(127 29 29);
    }

    .final-pl-table .indent {
      padding-left: 1.5rem;
      font-weight: 600;
      color: rgb(51 65 85);
    }

    .final-pl-table col.col-label { width: auto; }
    .final-pl-table col.col-inner { width: 22%; }
    .final-pl-table col.col-outer { width: 22%; }

    .final-pl-table tr.spacer-row td {
      padding: 0.35rem 0.85rem;
      background: white;
      border-top: 0;
    }

    .final-pl-table tr.grand-total-row td {
      background: rgb(254 252 232);
      color: rgb(120 53 15);
      font-weight: 800;
    }

    .final-pl-table .extra-tag {
      display: inline-block;
      margin-left: 0.5rem;
      padding: 0.05rem 0.45rem;
      border-radius: 0.5rem;
      background: rgb(241 245 249);
      font-size: 10px;
      font-weight: 700;
      color: rgb(71 85 105);
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .final-card {
      border: 1px solid rgb(226 232 240);
      border-radius: 1rem;
      background: white;
      box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
      overflow: hidden;
    }

    .profitloss-yearly-wrap {
      overflow-x: auto;
      overflow-y: hidden;
      max-width: 100%;
      -webkit-overflow-scrolling: touch;
    }

    .profitloss-yearly-table {
      width: max-content;
      min-width: 1480px;
      border-collapse: separate;
      border-spacing: 0;
    }

    .profitloss-yearly-table th,
    .profitloss-yearly-table td {
      padding: 0.55rem 0.65rem;
      font-size: 12px;
      line-height: 1.25rem;
      white-space: nowrap;
      vertical-align: top;
      border-top: 1px solid rgb(241 245 249);
    }

    .profitloss-yearly-table thead th {
      position: sticky;
      top: 0;
      z-index: 20;
      background: rgb(248 250 252 / 0.96);
    }

    .profitloss-sticky-col {
      position: sticky;
      left: 0;
      z-index: 15;
      min-width: 270px;
      max-width: 270px;
      background: white;
      box-shadow: inset -1px 0 0 rgb(226 232 240);
    }

    .profitloss-sticky-col.section {
      background: rgb(219 234 254);
    }

    .profitloss-sticky-col.total {
      background: rgb(248 250 252);
    }

    .profitloss-sticky-col.summary {
      background: rgb(239 246 255);
    }

    .profitloss-sticky-col.net {
      background: rgb(236 253 245);
    }

    .profitloss-cell {
      min-width: 108px;
      text-align: right;
    }

    .profitloss-cell-amount {
      font-weight: 600;
      color: rgb(15 23 42);
    }

    .profitloss-cell-amount.negative {
      color: rgb(190 24 93);
    }

    .profitloss-cell-trend {
      margin-top: 0.1rem;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.01em;
    }

    .profitloss-cell-trend.up {
      color: rgb(22 163 74);
    }

    .profitloss-cell-trend.down {
      color: rgb(220 38 38);
    }

    .profitloss-cell-trend.flat {
      color: rgb(100 116 139);
    }

    .profitloss-accordion summary::-webkit-details-marker {
      display: none;
    }

    .profitloss-accordion-summary {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 0.75rem;
      align-items: center;
      padding: 0.9rem 1rem;
      cursor: pointer;
      background: rgb(219 234 254 / 0.45);
    }

    .profitloss-accordion-title {
      display: inline-flex;
      min-width: 0;
      align-items: center;
      gap: 0.65rem;
      font-weight: 700;
      color: rgb(15 23 42);
    }

    .profitloss-accordion-icon {
      display: inline-flex;
      width: 1.35rem;
      height: 1.35rem;
      flex: 0 0 auto;
      align-items: center;
      justify-content: center;
      border-radius: 9999px;
      background: white;
      color: rgb(29 52 147);
      font-size: 0.9rem;
      font-weight: 800;
      line-height: 1;
      box-shadow: inset 0 0 0 1px rgb(191 219 254);
    }

    .profitloss-accordion-icon::before {
      content: "+";
    }

    .profitloss-accordion[open] .profitloss-accordion-icon::before {
      content: "-";
    }

    .profitloss-accordion-total {
      text-align: right;
      font-weight: 700;
      white-space: nowrap;
    }

    .profitloss-summary-row {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 0.75rem;
      align-items: center;
      padding: 0.9rem 1rem;
      border-top: 1px solid rgb(226 232 240);
    }

    .profitloss-net-meta {
      margin-top: 0.2rem;
      font-size: 0.75rem;
      font-weight: 600;
      color: rgb(71 85 105);
    }
  </style>
@endpush

@section('content')
  @php
    $reportRoute = $periodType === 'yearly'
      ? 'accountingapp.reports.profit-loss.yearly'
      : 'accountingapp.reports.profit-loss';

    $formatCurrency = function (float $amount): string {
      $prefix = $amount < 0 ? '(Rp ' : 'Rp ';
      $suffix = $amount < 0 ? ')' : '';

      return $prefix . number_format(abs($amount), 0, ',', '.') . $suffix;
    };

    $formatPercent = fn (?float $percent): string => $percent === null
      ? '-'
      : number_format($percent, 2, ',', '.') . '%';

    $yearMonthKeys = $yearlyMatrix
      ? collect($yearlyMatrix['months'])->pluck('key')->values()->all()
      : [];
    $placeholderMonthKeys = $yearlyMatrix
      ? collect($yearlyMatrix['months'])
          ->filter(fn ($month) => $month['is_placeholder'] ?? false)
          ->pluck('key')
          ->flip()
          ->all()
      : [];

    $trendData = function (array $values, string $currentKey) use ($yearMonthKeys, $placeholderMonthKeys): ?array {
      $index = array_search($currentKey, $yearMonthKeys, true);

      if ($index === false || $index === 0) {
        return null;
      }

      $previousKey = $yearMonthKeys[$index - 1];

      if (isset($placeholderMonthKeys[$currentKey]) || isset($placeholderMonthKeys[$previousKey])) {
        return null;
      }

      $previous = (float) ($values[$previousKey] ?? 0);
      $current = (float) ($values[$currentKey] ?? 0);

      if (abs($previous) < 0.005) {
        if (abs($current) < 0.005) {
          return null;
        }

        return [
          'label' => 'n/a',
          'class' => $current > 0 ? 'up' : ($current < 0 ? 'down' : 'flat'),
        ];
      }

      $percent = round((($current - $previous) / abs($previous)) * 100, 1);

      return [
        'label' => ($percent > 0 ? '+' : '') . number_format($percent, 1, ',', '.') . '%',
        'class' => $percent > 0 ? 'up' : ($percent < 0 ? 'down' : 'flat'),
      ];
    };

  @endphp

  <section class="dashboard-hero">
    <div class="page-toolbar">
      <div>
        <h1 class="dashboard-hero-title">Laporan Laba Rugi</h1>
        <p class="dashboard-hero-subtitle">
          Tab bulanan memakai worksheet satu periode. Tab tahunan menampilkan matrix Januari-Desember dengan tampilan yang lebih compact.
        </p>
      </div>
      @if($periodType === 'yearly')
        <div class="flex flex-wrap items-center gap-2">
          <a href="{{ route('accountingapp.reports.profit-loss') }}" class="btn-ghost">
            Kembali ke Tampilan Biasa
          </a>
          <a href="{{ route('accountingapp.dashboard') }}" class="btn-ghost">
            Dashboard Accounting
          </a>
        </div>
      @endif
    </div>

    <div class="mt-6 inline-flex items-center gap-1 rounded-xl bg-slate-100 p-1">
      <a
        href="{{ route('accountingapp.reports.profit-loss') }}"
        class="rounded-lg px-4 py-2 text-sm font-semibold transition {{ $periodType === 'monthly' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-800' }}"
      >
        Periode Bulanan
      </a>
      <a
        href="{{ route('accountingapp.reports.profit-loss.yearly', ['year' => $selectedYear]) }}"
        class="rounded-lg px-4 py-2 text-sm font-semibold transition {{ $periodType === 'yearly' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-800' }}"
      >
        Periode Tahunan
      </a>
    </div>

    <form method="GET" action="{{ route($reportRoute) }}" class="form-grid mt-6">

      @if($periodType === 'yearly')
        <div>
          <label class="form-label">Tahun Laporan</label>
          <select name="year" class="form-control">
            @foreach($availableYears as $year)
              <option value="{{ $year }}" @selected($selectedYear === (int) $year)>{{ $year }}</option>
            @endforeach
          </select>
        </div>

        <div></div>
      @else
        <div>
          <label class="form-label">Dari Tanggal</label>
          <input type="date" name="date_from" value="{{ $dateFrom->toDateString() }}" class="form-control">
        </div>

        <div>
          <label class="form-label">Sampai Tanggal</label>
          <input type="date" name="date_to" value="{{ $dateTo->toDateString() }}" class="form-control">
        </div>
      @endif

      <div class="flex items-end">
        <button type="submit" class="btn-primary w-full">Terapkan Filter</button>
      </div>

      <div class="flex items-end">
        <a
          href="{{ route($reportRoute, $periodType === 'yearly' ? ['year' => now()->year] : []) }}"
          class="btn-ghost w-full text-center"
        >
          Reset
        </a>
      </div>
    </form>
  </section>

  @include('partials.report-export-actions', [
    'excelUrl' => route($periodType === 'yearly' ? 'accountingapp.reports.profit-loss.yearly.export.excel' : 'accountingapp.reports.profit-loss.export.excel', request()->query()),
    'pdfUrl' => route($periodType === 'yearly' ? 'accountingapp.reports.profit-loss.yearly.export.pdf' : 'accountingapp.reports.profit-loss.export.pdf', request()->query()),
    'caption' => 'Export laba rugi mengikuti mode periode yang sedang aktif.',
  ])

  <section class="mt-6 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
    <strong>Status rentang laporan:</strong> {{ $rangePeriodStatus['message'] }}
  </section>

  <section class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
    <div class="font-semibold">Note</div>
    <div class="mt-1">
      Pembayaran hutang tidak dihitung sebagai beban agar tidak double count. Pembelian stok masuk ke HPP melalui pemakaian inventory.
    </div>
  </section>

  @if(($statement['inventoryWarnings'] ?? collect())->isNotEmpty())
    <section class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
      <div class="font-semibold">Catatan data yang masih kurang</div>
      <ul class="mt-2 list-disc space-y-1 pl-5">
        @foreach($statement['inventoryWarnings'] as $warning)
          <li>{{ $warning }}</li>
        @endforeach
      </ul>
    </section>
  @endif

  @if($periodType === 'yearly')
  <section class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
    <div class="stat-card">
      <div class="stat-label">Pendapatan</div>
      <div class="stat-value">Rp {{ number_format($statement['salesRevenue'], 0, ',', '.') }}</div>
      <div class="stat-meta">
        {{ $periodType === 'yearly' ? 'Akumulasi tahun ' . $selectedYear : $statement['salesActualCount'] . ' sales actual submitted' }}
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-label">Laba Kotor</div>
      <div class="stat-value {{ $statement['grossProfit'] >= 0 ? 'text-slate-900' : 'text-rose-700' }}">
        Rp {{ number_format(abs($statement['grossProfit']), 0, ',', '.') }}
      </div>
      <div class="stat-meta">Pendapatan dikurangi HPP</div>
    </div>

    <div class="stat-card">
      <div class="stat-label">Laba Operasional</div>
      <div class="stat-value {{ $statement['operatingProfit'] >= 0 ? 'text-slate-900' : 'text-rose-700' }}">
        Rp {{ number_format(abs($statement['operatingProfit']), 0, ',', '.') }}
      </div>
      <div class="stat-meta">Sesudah beban operasional</div>
    </div>

    <div class="stat-card">
      <div class="stat-label">{{ $statement['netProfit'] >= 0 ? 'Laba Bersih' : 'Rugi Bersih' }}</div>
      <div class="stat-value {{ $statement['netProfit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
        Rp {{ number_format(abs($statement['netProfit']), 0, ',', '.') }}
      </div>
      <div class="stat-meta">
        {{ $periodType === 'yearly' ? 'Akumulasi tahun berjalan' : 'Setelah pendapatan lain-lain' }}
      </div>
    </div>
  </section>
  @endif

  @if($periodType === 'yearly' && $yearlyMatrix)
    <section class="table-shell mt-6">
      <div class="table-head flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <span>Worksheet Laba Rugi Tahunan</span>
        <span class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Tahun {{ $selectedYear }}</span>
      </div>
      <div class="profitloss-yearly-wrap">
        <table class="profitloss-yearly-table">
          <thead>
            <tr>
              <th class="profitloss-sticky-col">Keterangan</th>
              @foreach($yearlyMatrix['months'] as $month)
                <th class="profitloss-cell">{{ $month['label'] }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach($yearlyMatrix['groups'] as $group)
              <tr style="background: rgb(219 234 254);">
                <td class="profitloss-sticky-col section font-bold text-slate-900">{{ $group['title'] }}</td>
                @foreach($yearlyMatrix['months'] as $month)
                  <td class="profitloss-cell"></td>
                @endforeach
              </tr>

              @forelse($group['rows'] as $row)
                <tr>
                  <td class="profitloss-sticky-col pl-8 font-medium text-slate-800">{{ $row['label'] }}</td>
                  @foreach($yearlyMatrix['months'] as $month)
                    @php
                      $amount = (float) ($row['values'][$month['key']] ?? 0);
                      $trend = $trendData($row['values'], $month['key']);
                    @endphp
                    <td class="profitloss-cell">
                      <div class="profitloss-cell-amount {{ $amount < 0 ? 'negative' : '' }}">{{ $formatCurrency($amount) }}</div>
                      @if($trend)
                        <div class="profitloss-cell-trend {{ $trend['class'] }}">{{ $trend['label'] }}</div>
                      @endif
                    </td>
                  @endforeach
                </tr>
              @empty
                <tr>
                  <td class="profitloss-sticky-col pl-8 text-slate-500">{{ $group['empty_label'] ?? 'Tidak ada data' }}</td>
                  @foreach($yearlyMatrix['months'] as $month)
                    <td class="profitloss-cell text-slate-400">Rp 0</td>
                  @endforeach
                </tr>
              @endforelse

              <tr style="background: rgb(248 250 252);">
                <td class="profitloss-sticky-col total font-semibold text-slate-900">{{ $group['total_label'] }}</td>
                @foreach($yearlyMatrix['months'] as $month)
                  @php
                    $amount = (float) ($group['total_values'][$month['key']] ?? 0);
                    $trend = $trendData($group['total_values'], $month['key']);
                  @endphp
                  <td class="profitloss-cell">
                    <div class="profitloss-cell-amount {{ $amount < 0 ? 'negative' : '' }}">{{ $formatCurrency($amount) }}</div>
                    @if($trend)
                      <div class="profitloss-cell-trend {{ $trend['class'] }}">{{ $trend['label'] }}</div>
                    @endif
                  </td>
                @endforeach
              </tr>

              @if(!empty($group['summary_label']))
                <tr style="background: rgb(239 246 255);">
                  <td class="profitloss-sticky-col summary font-bold text-slate-900">{{ $group['summary_label'] }}</td>
                  @foreach($yearlyMatrix['months'] as $month)
                    @php
                      $amount = (float) ($group['summary_values'][$month['key']] ?? 0);
                      $trend = $trendData($group['summary_values'], $month['key']);
                    @endphp
                    <td class="profitloss-cell">
                      <div class="profitloss-cell-amount {{ $amount < 0 ? 'negative' : '' }}">{{ $formatCurrency($amount) }}</div>
                      @if($trend)
                        <div class="profitloss-cell-trend {{ $trend['class'] }}">{{ $trend['label'] }}</div>
                      @endif
                    </td>
                  @endforeach
                </tr>
              @endif
            @endforeach

            <tr style="background: rgb(236 253 245);">
              <td class="profitloss-sticky-col net font-bold text-slate-900">Laba Bersih Per Bulan</td>
              @foreach($yearlyMatrix['months'] as $month)
                @php
                  $amount = (float) ($yearlyMatrix['net_profit_values'][$month['key']] ?? 0);
                  $trend = $trendData($yearlyMatrix['net_profit_values'], $month['key']);
                @endphp
                <td class="profitloss-cell">
                  <div class="profitloss-cell-amount {{ $amount < 0 ? 'negative' : '' }}">{{ $formatCurrency($amount) }}</div>
                  @if($trend)
                    <div class="profitloss-cell-trend {{ $trend['class'] }}">{{ $trend['label'] }}</div>
                  @endif
                </td>
              @endforeach
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  @else
    @php
      $finalFormat = function (float $amount) {
        if (abs($amount) < 0.005) {
          return 'Rp 0';
        }
        $prefix = $amount < 0 ? '(Rp ' : 'Rp ';
        $suffix = $amount < 0 ? ')' : '';
        return $prefix . number_format(abs($amount), 0, ',', '.') . $suffix;
      };
      $penjualan = (float) ($profitLoss['totalPenjualan'] ?? 0);
      $laba = (float) ($profitLoss['labaRugi'] ?? 0);
      $marginPct = abs($penjualan) > 0.005 ? ($laba / $penjualan) * 100 : 0;
      $marginLabel = number_format($marginPct, 2, ',', '.') . '%';
    @endphp

    <section class="space-y-3 mt-6">
      <div>
        <h2 class="text-lg font-bold text-slate-900">Laporan Laba Rugi</h2>
        <p class="text-xs font-semibold text-slate-500">
          Rentang {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}.
        </p>
      </div>

      <div class="final-card">
        <table class="final-pl-table">
          <colgroup>
            <col class="col-label">
            <col class="col-inner">
            <col class="col-outer">
          </colgroup>
          <tbody>
            <tr class="major-row">
              <td class="label-cell">PENJUALAN</td>
              <td class="value-cell">{{ $finalFormat((float) $profitLoss['totalPenjualan']) }}</td>
              <td class="value-cell"></td>
            </tr>

            <tr>
              <td class="indent">Bahan Baku Lama</td>
              <td class="value-cell"></td>
              <td class="value-cell">{{ $finalFormat((float) $profitLoss['bahanBakuLama']) }}</td>
            </tr>
            <tr>
              <td class="indent">Bahan Baku Baru</td>
              <td class="value-cell"></td>
              <td class="value-cell">{{ $finalFormat((float) $profitLoss['bahanBakuBaru']) }}</td>
            </tr>
            <tr>
              <td class="indent">Sisa Stok</td>
              <td class="value-cell"></td>
              <td class="value-cell">{{ $finalFormat((float) $profitLoss['sisaStok']) }}</td>
            </tr>
            <tr class="subtotal-row">
              <td class="label-cell">Bahan Baku Terpakai</td>
              <td class="value-cell"></td>
              <td class="value-cell">{{ $finalFormat((float) $profitLoss['bahanBakuTerpakai']) }}</td>
            </tr>

            <tr class="spacer-row"><td colspan="3">&nbsp;</td></tr>

            <tr class="section-row">
              <td class="label-cell">PENGELUARAN</td>
              <td class="value-cell"></td>
              <td class="value-cell"></td>
            </tr>

            @foreach ($profitLoss['pengeluaranRows'] as $row)
              <tr>
                <td class="indent">{{ $row['label'] }}</td>
                <td class="value-cell"></td>
                <td class="value-cell">{{ $finalFormat((float) $row['amount']) }}</td>
              </tr>
            @endforeach

            <tr class="subtotal-row">
              <td class="label-cell">TOTAL</td>
              <td class="value-cell"></td>
              <td class="value-cell">{{ $finalFormat((float) $profitLoss['totalPengeluaran']) }}</td>
            </tr>

            <tr class="spacer-row"><td colspan="3">&nbsp;</td></tr>

            <tr class="profit-row {{ $laba < 0 ? 'negative' : '' }}">
              <td class="label-cell">
                LABA
                <span class="extra-tag">{{ $marginLabel }}</span>
              </td>
              <td class="value-cell"></td>
              <td class="value-cell">{{ $finalFormat($laba) }}</td>
            </tr>

            <tr class="grand-total-row">
              <td class="label-cell">TOTAL</td>
              <td class="value-cell">{{ $finalFormat((float) $profitLoss['totalPenjualan']) }}</td>
              <td class="value-cell">{{ $finalFormat((float) $profitLoss['total']) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  @endif
@endsection
