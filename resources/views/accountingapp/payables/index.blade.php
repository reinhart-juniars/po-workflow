@extends('layouts.accountingapp', ['title' => 'Monitoring Hutang'])

@section('content')
  <h1 class="page-title mb-6">Monitoring Hutang</h1>

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
    <x-table-toolbar title="Daftar Hutang Outstanding" :action="route('accountingapp.payables.index')"
      :shortcuts="[
        'Lewat jatuh tempo' => route('accountingapp.payables.index', ['urgency' => 'overdue']),
        'Jatuh tempo hari ini' => route('accountingapp.payables.index', ['urgency' => 'today']),
        '7 hari lagi' => route('accountingapp.payables.index', ['urgency' => 'next_7_days']),
      ]"
      :filters="[
        ['type' => 'text', 'name' => 'supplier_name', 'label' => 'Supplier', 'value' => $supplierName,
         'placeholder' => 'Cari supplier', 'list' => 'supplier-options'],
        ['type' => 'select', 'name' => 'source', 'label' => 'Sumber', 'value' => $source, 'placeholder' => 'Semua sumber',
         'options' => ['inventory_purchase' => 'Pembelian Stok', 'opening_balance' => 'Saldo Awal', 'other' => 'Hutang Lain']],
        ['type' => 'select', 'name' => 'urgency', 'label' => 'Status Jatuh Tempo', 'value' => $urgency, 'placeholder' => 'Semua',
         'options' => ['overdue' => 'Lewat Jatuh Tempo', 'today' => 'Hari Ini', 'next_7_days' => '7 Hari Lagi', 'no_due_date' => 'Tanpa Jatuh Tempo']],
        ['type' => 'date-range', 'label' => 'Jatuh Tempo', 'from' => 'date_from', 'to' => 'date_to',
         'value' => [$dateFrom ?? null, $dateTo ?? null]],
      ]" />
    @include('partials.supplier-datalist')


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

@endsection
