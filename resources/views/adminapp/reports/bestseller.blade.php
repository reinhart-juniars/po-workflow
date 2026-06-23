@extends('layouts.adminapp', ['title' => 'Laporan Best Seller per Customer'])

@section('content')
@php
  $grandQty = $results->sum('total_qty');
  $grandFreq = $results->sum('total_orders');
  $grandTotal = $results->sum('total_amount');
@endphp

<section class="dashboard-hero">
  <div class="page-toolbar">
    <div>
      <h1 class="dashboard-hero-title">Laporan Menu Best Seller</h1>
      <p class="dashboard-hero-subtitle">
        Analisis menu terlaris per customer berdasarkan qty, frekuensi order, dan omzet.
      </p>
    </div>
  </div>
</section>

<section class="form-shell mt-4">
  <form method="GET" action="{{ route('adminapp.reports.bestseller') }}" class="space-y-4">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
      <div>
        <label class="form-label">Dari Tanggal</label>
        <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control">
      </div>

      <div>
        <label class="form-label">Sampai Tanggal</label>
        <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control">
      </div>

      <div>
        <label class="form-label">Customer</label>
        <select name="customer_id" class="form-control">
          <option value="">Pilih Customer</option>
          @foreach ($customers as $c)
            <option value="{{ $c->id }}" @selected($customerId == $c->id)>
              {{ $c->name }}
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Top N (1-10)</label>
        <input type="number" name="limit" min="1" max="10" value="{{ $limit }}" class="form-control">
      </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:max-w-md">
      <button class="btn-primary w-full" type="submit">Lihat</button>
      <a href="{{ route('adminapp.reports.bestseller') }}" class="btn-ghost w-full text-center">Reset</a>
    </div>
  </form>

</section>

@if($selectedCustomer)
  @include('partials.report-export-actions', [
    'excelUrl' => route('adminapp.reports.bestseller.export.excel', request()->query()),
    'pdfUrl' => route('adminapp.reports.bestseller.export.pdf', request()->query()),
    'caption' => 'Export best seller mengikuti customer, periode, dan Top N yang sedang dipilih.',
  ])
@endif

@if(!$customerId)
  <div class="flash-error mt-4 bg-amber-50 border-amber-200 text-amber-800">
    Silakan pilih <strong>customer</strong> dan rentang tanggal, lalu klik <strong>Lihat</strong>.
  </div>
@elseif($selectedCustomer && $results->isEmpty())
  <div class="flash-error mt-4 bg-sky-50 border-sky-200 text-sky-800">
    Tidak ada data untuk customer <strong>{{ $selectedCustomer->name }}</strong> pada periode {{ $dateFrom }} s/d {{ $dateTo }}.
  </div>
@endif

@if($selectedCustomer && $results->isNotEmpty())
  <section class="stats-grid mt-4">
    <article class="stat-card">
      <p class="stat-label">Customer</p>
      <p class="stat-value text-xl">{{ $selectedCustomer->name }}</p>
      <p class="stat-meta">Top {{ $limit }} menu</p>
    </article>
    <article class="stat-card">
      <p class="stat-label">Total Qty</p>
      <p class="stat-value text-brand-600">{{ number_format($grandQty) }}</p>
      <p class="stat-meta">Akumulasi kuantitas</p>
    </article>
    <article class="stat-card">
      <p class="stat-label">Frekuensi Order</p>
      <p class="stat-value text-sky-600">{{ number_format($grandFreq) }}</p>
      <p class="stat-meta">Jumlah transaksi item</p>
    </article>
    <article class="stat-card">
      <p class="stat-label">Total Omzet</p>
      <p class="stat-value text-emerald-600">Rp {{ number_format($grandTotal, 0, ',', '.') }}</p>
      <p class="stat-meta">Periode {{ $dateFrom }} s/d {{ $dateTo }}</p>
    </article>
  </section>

  <section class="table-shell mt-4">
    <div class="table-head">Daftar Menu Best Seller</div>
    <div class="data-table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Produk</th>
            <th class="text-right">Total Qty</th>
            <th class="text-right">Frekuensi</th>
            <th class="text-right">Total Omzet</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($results as $index => $row)
            <tr>
              <td>{{ $index + 1 }}</td>
              <td>{{ $row->product_name }}</td>
              <td class="text-right">{{ $row->total_qty }}</td>
              <td class="text-right">{{ $row->total_orders }}</td>
              <td class="text-right font-semibold">Rp {{ number_format($row->total_amount, 0, ',', '.') }}</td>
            </tr>
          @endforeach
          <tr class="bg-slate-50 font-semibold">
            <td colspan="2">TOTAL</td>
            <td class="text-right">{{ $grandQty }}</td>
            <td class="text-right">{{ $grandFreq }}</td>
            <td class="text-right">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
@endif
@endsection
