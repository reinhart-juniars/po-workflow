@extends('layouts.accountingapp', ['title' => 'Monitoring Piutang'])

@section('content')
  @php
    $viewMode = $viewMode ?? 'outstanding';
    $isPaidView = $viewMode === 'paid';
    $isShowingAllOutstanding = ! $isPaidView
        && blank($dateFrom)
        && blank($dateTo)
        && blank($urgency ?? null)
        && blank($customerSearch ?? null)
        && blank($poNumberSearch ?? null);
  @endphp

  <h1 class="text-2xl font-bold mb-4">Monitoring Piutang</h1>

  {{-- Tab nav --}}
  <div class="mb-4 flex flex-wrap gap-2">
    <a href="{{ route('accountingapp.periods.index') }}"
       class="chip {{ ! $isPaidView ? 'bg-brand-500 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50' }}">
      Outstanding
    </a>
    <a href="{{ route('accountingapp.periods.index', ['view' => 'paid']) }}"
       class="chip {{ $isPaidView ? 'bg-brand-500 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50' }}">
      Sudah Dilunasi
    </a>
  </div>

  @if (! $isPaidView)
  <div class="mb-3 notice-soft-amber">
    Piutang jatuh tempo hari ini / lewat jatuh tempo: <strong>{{ number_format($dueReceivablesCount ?? 0) }}</strong> |
    7 hari ke depan: <strong>{{ number_format($dueSoonReceivablesCount ?? 0) }}</strong>
  </div>
  @endif

  @include('partials.report-export-actions', [
    'excelUrl' => route('accountingapp.periods.export.excel', request()->query()),
    'pdfUrl' => route('accountingapp.periods.export.pdf', request()->query()),
    'caption' => $isPaidView
        ? 'Export daftar piutang yang sudah dilunasi mengikuti filter yang aktif.'
        : 'Export monitoring piutang mengikuti filter jatuh tempo yang sedang aktif.',
  ])

  @if ($isPaidView)
    {{-- ====== PAID VIEW ====== --}}
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 mb-6">
      <div class="stat-card">
        <div class="stat-label">Jumlah PO Lunas</div>
        <div class="stat-value text-emerald-600">{{ number_format($countPaid ?? 0, 0, ',', '.') }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total Nominal Pelunasan</div>
        <div class="stat-value text-emerald-700">Rp {{ number_format((float) ($totalPaid ?? 0), 0, ',', '.') }}</div>
      </div>
    </div>

    <div class="table-card">
      <div class="table-card-head">Daftar Piutang Sudah Dilunasi</div>

      <div class="p-5 border-b bg-slate-50/70">
        <form method="GET" action="{{ route('accountingapp.periods.index') }}" class="grid grid-cols-1 md:grid-cols-5 gap-3">
          <input type="hidden" name="view" value="paid">

          <div class="md:col-span-5 flex flex-wrap gap-2">
            <button type="button" class="chip-filter js-date-preset" data-form-scope="paid-filter" data-preset="this_month">Bulan Ini</button>
            <button type="button" class="chip-filter js-date-preset" data-form-scope="paid-filter" data-preset="last_month">Bulan Lalu</button>
            <button type="button" class="chip-filter js-date-preset" data-form-scope="paid-filter" data-preset="this_year">Tahun Berjalan</button>
          </div>

          <div>
            <label class="form-label">Tgl Bayar Dari</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}"
                   data-form-scope="paid-filter" data-role="date-from"
                   class="form-control">
          </div>

          <div>
            <label class="form-label">Tgl Bayar Sampai</label>
            <input type="date" name="date_to" value="{{ $dateTo }}"
                   data-form-scope="paid-filter" data-role="date-to"
                   class="form-control">
          </div>

          <div>
            <label class="form-label">Akun Kas</label>
            <select name="cash_account_id" class="form-control">
              <option value="">Semua Akun</option>
              @foreach($cashAccounts as $cashAccount)
                <option value="{{ $cashAccount->id }}" @selected((string)($cashAccountId ?? '') === (string) $cashAccount->id)>
                  {{ $cashAccount->name }} ({{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
                </option>
              @endforeach
            </select>
          </div>

          <div>
            <label class="form-label">Customer</label>
            <input type="text" name="customer" value="{{ $customerSearch ?? '' }}"
                   placeholder="Cari nama customer" class="form-control">
          </div>

          <div class="flex items-end justify-end gap-2">
            <a href="{{ route('accountingapp.periods.index', ['view' => 'paid']) }}" class="btn-outline">Reset</a>
            <button class="btn-secondary">Filter</button>
          </div>
        </form>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="text-left px-4 py-2">Tgl Pembayaran</th>
              <th class="text-left px-4 py-2">No. PO</th>
              <th class="text-left px-4 py-2">Nama Customer</th>
              <th class="text-left px-4 py-2">Akun Kas</th>
              <th class="text-right px-4 py-2">Nominal</th>
            </tr>
          </thead>
          <tbody>
            @forelse($periods as $po)
              <tr class="border-t">
                <td class="px-4 py-2">
                  {{ optional($po->cash_received_at)->format('d-m-Y') ?? '-' }}
                  <div class="mt-1 text-xs text-gray-500">
                    {{ optional($po->cash_received_at)->format('H:i') }}
                  </div>
                </td>
                <td class="px-4 py-2">
                  {{ $po->po_number }}
                  @if (! empty($po->items_summary))
                    <div class="mt-1 text-xs text-gray-500">{{ $po->items_summary }}</div>
                  @endif
                </td>
                <td class="px-4 py-2">{{ $po->customer->name ?? '-' }}</td>
                <td class="px-4 py-2">
                  @if ($po->cashAccount)
                    {{ $po->cashAccount->name }}
                    <span class="text-xs text-gray-500">({{ $po->cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})</span>
                  @else
                    -
                  @endif
                </td>
                <td class="px-4 py-2 text-right">Rp {{ number_format((float) $po->total_amount, 0, ',', '.') }}</td>
              </tr>
            @empty
              <tr>
                <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                  Belum ada piutang yang dilunasi pada periode ini.
                </td>
              </tr>
            @endforelse
          </tbody>
          @if (($countPaid ?? 0) > 0)
            <tfoot class="bg-slate-50">
              <tr>
                <td colspan="4" class="px-4 py-2 text-right font-semibold">Total</td>
                <td class="px-4 py-2 text-right font-semibold">Rp {{ number_format((float) ($totalPaid ?? 0), 0, ',', '.') }}</td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>

      <div class="p-4">
        {{ $periods->links() }}
      </div>
    </div>

    <script>
      (() => {
        const formatDate = (date) => {
          const y = date.getFullYear();
          const m = String(date.getMonth() + 1).padStart(2, '0');
          const d = String(date.getDate()).padStart(2, '0');
          return `${y}-${m}-${d}`;
        };
        const applyPreset = (preset, fromInput, toInput) => {
          const now = new Date();
          let start, end;
          if (preset === 'this_month') {
            start = new Date(now.getFullYear(), now.getMonth(), 1);
            end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
          } else if (preset === 'last_month') {
            start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            end = new Date(now.getFullYear(), now.getMonth(), 0);
          } else if (preset === 'this_year') {
            start = new Date(now.getFullYear(), 0, 1);
            end = new Date(now.getFullYear(), 11, 31);
          } else return;
          fromInput.value = formatDate(start);
          toInput.value = formatDate(end);
        };
        document.querySelectorAll('.js-date-preset[data-form-scope="paid-filter"]').forEach((button) => {
          button.addEventListener('click', () => {
            const scope = button.dataset.formScope;
            const preset = button.dataset.preset;
            const fromInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-from"]`);
            const toInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-to"]`);
            if (!fromInput || !toInput) return;
            applyPreset(preset, fromInput, toInput);
          });
        });
      })();
    </script>

  @else
    {{-- ====== OUTSTANDING VIEW ====== --}}

  <div class="grid grid-cols-1 gap-3 md:grid-cols-4 mb-6">
    <div class="stat-card">
      <div class="stat-label">Lewat Jatuh Tempo</div>
      <div class="stat-value text-red-600">{{ number_format($overdueReceivablesCount ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Jatuh Tempo Hari Ini</div>
      <div class="stat-value text-amber-600">{{ number_format($dueTodayReceivablesCount ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">7 Hari Ke Depan</div>
      <div class="stat-value {{ ($dueSoonReceivablesCount ?? 0) > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ number_format($dueSoonReceivablesCount ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Tanpa Jatuh Tempo</div>
      <div class="stat-value text-slate-700">{{ number_format($openReceivablesWithoutDueDateCount ?? 0, 0, ',', '.') }}</div>
    </div>
  </div>

  <div class="table-card">
    <div class="table-card-head">
      Daftar Monitoring Piutang
    </div>

    <div class="p-5 border-b bg-slate-50/70">
      <form method="GET" action="{{ route('accountingapp.periods.index') }}" class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div class="md:col-span-5 flex flex-wrap gap-2">
          <a
            href="{{ route('accountingapp.periods.index') }}"
            class="chip {{ $isShowingAllOutstanding ? 'bg-brand-500 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50' }}"
          >
            Semua Piutang Outstanding
          </a>
          <button type="button" class="chip-filter js-date-preset" data-form-scope="receivables-filter" data-preset="this_month">Bulan Ini</button>
          <button type="button" class="chip-filter js-date-preset" data-form-scope="receivables-filter" data-preset="last_month">Bulan Lalu</button>
          <button type="button" class="chip-filter js-date-preset" data-form-scope="receivables-filter" data-preset="this_year">Tahun Berjalan</button>
          <a href="{{ route('accountingapp.periods.index', ['urgency' => 'overdue']) }}" class="chip-link">Overdue</a>
          <a href="{{ route('accountingapp.periods.index', ['urgency' => 'today']) }}" class="chip-link">Hari Ini</a>
          <a href="{{ route('accountingapp.periods.index', ['urgency' => 'next_7_days']) }}" class="chip-link">7 Hari Lagi</a>
          <a href="{{ route('accountingapp.periods.index', ['urgency' => 'no_due_date']) }}" class="chip-link">Tanpa Jatuh Tempo</a>
        </div>

        <div>
          <label class="form-label">Jatuh Tempo Dari</label>
          <input type="date" name="date_from" value="{{ $dateFrom }}"
                 data-form-scope="receivables-filter" data-role="date-from"
                 class="form-control">
        </div>

        <div>
          <label class="form-label">Jatuh Tempo Sampai</label>
          <input type="date" name="date_to" value="{{ $dateTo }}"
                 data-form-scope="receivables-filter" data-role="date-to"
                 class="form-control">
        </div>

        <div>
          <label class="form-label">Status Jatuh Tempo</label>
          <select name="urgency" class="form-control">
            <option value="">Semua</option>
            <option value="overdue" @selected(($urgency ?? null) === 'overdue')>Lewat Jatuh Tempo</option>
            <option value="today" @selected(($urgency ?? null) === 'today')>Hari Ini</option>
            <option value="next_7_days" @selected(($urgency ?? null) === 'next_7_days')>7 Hari Lagi</option>
            <option value="no_due_date" @selected(($urgency ?? null) === 'no_due_date')>Tanpa Jatuh Tempo</option>
          </select>
        </div>

        <div>
          <label class="form-label">Customer</label>
          <input type="text" name="customer" value="{{ $customerSearch ?? '' }}"
                 placeholder="Cari nama customer" class="form-control" autocomplete="off">
        </div>

        <div>
          <label class="form-label">No. PO</label>
          <input type="text" name="po_number" value="{{ $poNumberSearch ?? '' }}"
                 placeholder="Cari no PO" class="form-control" autocomplete="off">
        </div>

        <div class="md:col-span-3 flex items-end justify-end gap-2">
          <a href="{{ route('accountingapp.periods.index') }}" class="btn-outline">
            Reset
          </a>
          <button class="btn-secondary">Filter</button>
        </div>
      </form>
    </div>

    @if (($legacyReceivablesCount ?? 0) > 0)
      <div class="mx-5 mt-5 notice-soft-amber">
        Terdeteksi <strong>{{ number_format($legacyReceivablesCount, 0, ',', '.') }}</strong> piutang dari data lama.
        Baris dengan badge <span class="badge-soft-slate">Legacy</span> tetap dihitung outstanding selama belum ada penerimaan kas.
      </div>
    @endif

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="text-left px-4 py-2">Tanggal PO</th>
            <th class="text-left px-4 py-2">Nama Customer</th>
            <th class="text-left px-4 py-2">Pesanan</th>
            <th class="text-right px-4 py-2">Total Nilai</th>
            <th class="text-left px-4 py-2">Jatuh Tempo</th>
            <th class="text-left px-4 py-2">Sisa Hari</th>
            <th class="text-left px-4 py-2">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($periods as $po)
            @php
              $days = $po->days_remaining;
              $isOverdue = is_numeric($days) && $days < 0;
            @endphp
            <tr class="border-t">
              <td class="px-4 py-2">
                {{ optional($po->created_at)->format('d-m-Y') ?? '-' }}
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                  <span>{{ $po->po_number }}</span>
                  @if ($po->is_legacy_receivable ?? false)
                    <span class="badge-soft-slate">Legacy</span>
                  @endif
                </div>
              </td>
              <td class="px-4 py-2">{{ $po->customer->name ?? '-' }}</td>
              <td class="px-4 py-2">{{ $po->items_summary ?: '-' }}</td>
              <td class="px-4 py-2 text-right">Rp {{ number_format((float) $po->total_amount, 0, ',', '.') }}</td>
              <td class="px-4 py-2">
                {{ $po->due_date ? $po->due_date->format('d-m-Y') : '-' }}
              </td>
              <td class="px-4 py-2">
                @if (is_null($days))
                  -
                @elseif ($days < 0)
                  <span class="text-red-600 font-medium">Telat {{ abs((int) $days) }} hari</span>
                @elseif ($days === 0)
                  <span class="text-amber-600 font-medium">Jatuh tempo hari ini</span>
                @else
                  <span class="{{ $days <= 3 ? 'text-amber-600' : 'text-gray-700' }}">{{ (int) $days }} hari lagi</span>
                @endif
              </td>
              <td class="px-4 py-2">
                <form method="POST" action="{{ route('accountingapp.periods.complete', $po->id) }}"
                      onsubmit="return confirm('Konfirmasi pembayaran piutang ini?')">
                  @csrf
                  <div class="flex flex-col gap-2">
                    <input type="date" name="cash_received_at" class="form-control"
                           value="{{ now()->toDateString() }}"
                           max="{{ now()->toDateString() }}"
                           required
                           title="Tanggal pelunasan piutang">
                    <select name="cash_account_id" class="form-control" required>
                      <option value="">Pilih Akun Kas</option>
                      @foreach($cashAccounts as $cashAccount)
                        <option value="{{ $cashAccount->id }}">
                          {{ $cashAccount->name }} ({{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
                        </option>
                      @endforeach
                    </select>
                    <button type="submit" class="btn-success">
                      Konfirmasi Pembayaran
                    </button>
                  </div>
                </form>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="7" class="px-4 py-6 text-center text-gray-500">
                @if (!empty($customerSearch) || !empty($poNumberSearch))
                  Tidak ada piutang outstanding yang cocok dengan filter pencarian.
                @else
                  Belum ada piutang outstanding.
                @endif
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4">
      {{ $periods->links() }}
    </div>
  </div>

  <script>
    (() => {
      const formatDate = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
      };

      const applyPreset = (preset, fromInput, toInput) => {
        const now = new Date();
        let start;
        let end;

        if (preset === 'this_month') {
          start = new Date(now.getFullYear(), now.getMonth(), 1);
          end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        } else if (preset === 'last_month') {
          start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
          end = new Date(now.getFullYear(), now.getMonth(), 0);
        } else if (preset === 'this_year') {
          start = new Date(now.getFullYear(), 0, 1);
          end = new Date(now.getFullYear(), 11, 31);
        } else {
          return;
        }

        fromInput.value = formatDate(start);
        toInput.value = formatDate(end);
      };

      document.querySelectorAll('.js-date-preset').forEach((button) => {
        button.addEventListener('click', () => {
          const scope = button.dataset.formScope;
          const preset = button.dataset.preset;
          const fromInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-from"]`);
          const toInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-to"]`);

          if (!fromInput || !toInput) {
            return;
          }

          applyPreset(preset, fromInput, toInput);
        });
      });
    })();
  </script>
  @endif
@endsection


