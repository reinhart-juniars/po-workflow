@extends('layouts.accountingapp', ['title' => 'Monitoring Hutang'])

@section('content')
  <h1 class="text-2xl font-bold mb-4">Monitoring Hutang</h1>

  <div class="mb-4 notice-soft-amber">
    Hutang lewat jatuh tempo: <strong>{{ number_format($overduePayablesCount, 0, ',', '.') }}</strong> |
    Jatuh tempo hari ini: <strong>{{ number_format($dueTodayPayablesCount, 0, ',', '.') }}</strong> |
    7 hari ke depan: <strong>{{ number_format($dueSoonPayablesCount, 0, ',', '.') }}</strong>
  </div>

  <div class="grid grid-cols-1 gap-3 md:grid-cols-4 mb-6">
    <div class="stat-card">
      <div class="stat-label">Outstanding Hutang</div>
      <div class="stat-value text-rose-600">Rp {{ number_format((float) $outstandingPayable, 0, ',', '.') }}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Lewat Jatuh Tempo</div>
      <div class="stat-value text-red-600">{{ number_format($overduePayablesCount, 0, ',', '.') }}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Jatuh Tempo Hari Ini</div>
      <div class="stat-value text-amber-600">{{ number_format($dueTodayPayablesCount, 0, ',', '.') }}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Tanpa Jatuh Tempo</div>
      <div class="stat-value text-slate-700">{{ number_format($openPayablesWithoutDueDateCount, 0, ',', '.') }}</div>
    </div>
  </div>

  <div class="table-card">
    <div class="table-card-head">Daftar Hutang Outstanding</div>

    <div class="p-5 border-b bg-slate-50/70">
      <form method="GET" action="{{ route('accountingapp.payables.index') }}" class="grid grid-cols-1 gap-3 md:grid-cols-5">
        <div class="md:col-span-5 flex flex-wrap gap-2">
          <button type="button" class="chip-filter js-date-preset" data-form-scope="payables-filter" data-preset="this_month">Bulan Ini</button>
          <button type="button" class="chip-filter js-date-preset" data-form-scope="payables-filter" data-preset="last_month">Bulan Lalu</button>
          <button type="button" class="chip-filter js-date-preset" data-form-scope="payables-filter" data-preset="this_year">Tahun Berjalan</button>
          <a href="{{ route('accountingapp.payables.index', ['urgency' => 'overdue']) }}" class="chip-link">Overdue</a>
          <a href="{{ route('accountingapp.payables.index', ['urgency' => 'today']) }}" class="chip-link">Hari Ini</a>
          <a href="{{ route('accountingapp.payables.index', ['urgency' => 'next_7_days']) }}" class="chip-link">7 Hari Lagi</a>
        </div>

        <div>
          <label class="form-label">Supplier</label>
          <input type="text" name="supplier_name" value="{{ $supplierName }}"
                 class="form-control" placeholder="Cari supplier">
        </div>

        <div>
          <label class="form-label">Sumber</label>
          <select name="source" class="form-control">
            <option value="">Semua Sumber</option>
            <option value="inventory_purchase" @selected($source === 'inventory_purchase')>Pembelian Stok</option>
            <option value="opening_balance" @selected($source === 'opening_balance')>Saldo Awal</option>
            <option value="other" @selected($source === 'other')>Hutang Lain</option>
          </select>
        </div>

        <div>
          <label class="form-label">Status Jatuh Tempo</label>
          <select name="urgency" class="form-control">
            <option value="">Semua</option>
            <option value="overdue" @selected($urgency === 'overdue')>Lewat Jatuh Tempo</option>
            <option value="today" @selected($urgency === 'today')>Hari Ini</option>
            <option value="next_7_days" @selected($urgency === 'next_7_days')>7 Hari Lagi</option>
            <option value="no_due_date" @selected($urgency === 'no_due_date')>Tanpa Jatuh Tempo</option>
          </select>
        </div>

        <div>
          <label class="form-label">Jatuh Tempo Dari</label>
          <input type="date" name="date_from" value="{{ $dateFrom ?? '' }}"
                 data-form-scope="payables-filter" data-role="date-from"
                 class="form-control">
        </div>

        <div>
          <label class="form-label">Jatuh Tempo Sampai</label>
          <input type="date" name="date_to" value="{{ $dateTo ?? '' }}"
                 data-form-scope="payables-filter" data-role="date-to"
                 class="form-control">
        </div>

        <div class="md:col-span-5 flex items-end justify-end gap-2">
          <a href="{{ route('accountingapp.payables.index') }}" class="btn-outline">
            Reset
          </a>
          <button class="btn-secondary">Filter</button>
        </div>
      </form>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="text-left px-4 py-2">Supplier</th>
            <th class="text-left px-4 py-2">Sumber</th>
            <th class="text-left px-4 py-2">Tanggal Transaksi</th>
            <th class="text-left px-4 py-2">Jatuh Tempo</th>
            <th class="text-left px-4 py-2">Sisa Hari</th>
            <th class="text-right px-4 py-2">Nominal</th>
            <th class="text-left px-4 py-2">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($payables as $payable)
            @php
              $days = $payable->days_remaining;
            @endphp
            <tr class="border-t">
              <td class="px-4 py-2">
                <div>{{ $payable->supplier_name }}</div>
                <div class="text-xs text-slate-500">{{ $payable->description ?: '-' }}</div>
              </td>
              <td class="px-4 py-2">
                <div>{{ $payable->source_label }}</div>
                <div class="text-xs text-slate-500">{{ $payable->source_detail }}</div>
              </td>
              <td class="px-4 py-2">{{ $payable->transaction_date?->format('d-m-Y') ?? '-' }}</td>
              <td class="px-4 py-2">
                @if($payable->due_date)
                  {{ $payable->due_date->format('d-m-Y') }}
                @else
                  <span class="text-slate-500">Belum diatur</span>
                @endif
              </td>
              <td class="px-4 py-2">
                @if (is_null($days))
                  <span class="text-slate-500">Tanpa jatuh tempo</span>
                @elseif ($days < 0)
                  <span class="font-medium text-red-600">Telat {{ abs((int) $days) }} hari</span>
                @elseif ($days === 0)
                  <span class="font-medium text-amber-600">Jatuh tempo hari ini</span>
                @else
                  <span class="{{ $days <= 3 ? 'text-amber-600 font-medium' : 'text-slate-700' }}">{{ (int) $days }} hari lagi</span>
                @endif
              </td>
              <td class="px-4 py-2 text-right">Rp {{ number_format((float) $payable->amount, 0, ',', '.') }}</td>
              <td class="px-4 py-2">
                <a href="{{ route('accountingapp.expenses.index', ['tab' => 'payable-settlement', 'payable_id' => $payable->id]) }}"
                   class="btn-link">
                  Bayar
                </a>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="7" class="px-4 py-6 text-center text-gray-500">
                Belum ada hutang outstanding.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4">
      {{ $payables->links() }}
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
@endsection
