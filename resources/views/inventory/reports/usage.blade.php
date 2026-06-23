@extends('layouts.accountingapp', ['title' => 'Laporan Pemakaian Bahan'])

@section('content')
  <section class="dashboard-hero">
    <div class="page-toolbar">
      <div>
        <h1 class="dashboard-hero-title">Laporan Pemakaian Bahan</h1>
        <p class="dashboard-hero-subtitle">
          Pantau bahan baku lama, pembelian, sisa stok, dan total pemakaian setiap item inventory berdasarkan periode yang dipilih.
        </p>
      </div>
    </div>

    <form method="GET" action="{{ route('accountingapp.reports.inventory-usage') }}" class="form-grid mt-6">
      <div>
        <label class="form-label">Item</label>
        <select name="inventory_item_id" class="form-control" required>
          <option value="">Pilih Item</option>
          @foreach($items as $item)
            <option value="{{ $item->id }}" @selected($selectedItemId === $item->id)>
              {{ $item->name }} ({{ $item->unit }})
            </option>
          @endforeach
        </select>
      </div>

      <div>
        <label class="form-label">Dari Tanggal</label>
        <input type="date" name="date_from" value="{{ $dateFrom->toDateString() }}" class="form-control">
      </div>

      <div>
        <label class="form-label">Sampai Tanggal</label>
        <input type="date" name="date_to" value="{{ $dateTo->toDateString() }}" class="form-control">
      </div>

      <div class="flex items-end">
        <button type="submit" class="btn-primary w-full">
          Tampilkan Laporan
        </button>
      </div>
    </form>
  </section>

  @if($summary)
    @include('partials.report-export-actions', [
      'excelUrl' => route('accountingapp.reports.inventory-usage.export.excel', request()->query()),
      'pdfUrl' => route('accountingapp.reports.inventory-usage.export.pdf', request()->query()),
      'caption' => 'Export laporan pemakaian bahan mengikuti item dan rentang tanggal yang sedang dipilih.',
    ])
  @endif

  <section class="mt-6 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
    @if($summary)
      Detail di bawah menampilkan komponen perhitungan pemakaian untuk item
      <strong>{{ $selectedItem?->name ?? '-' }}</strong>
      pada periode {{ $dateFrom->format('d M Y') }} - {{ $dateTo->format('d M Y') }}.
    @else
      Pilih item bahan baku terlebih dulu untuk menampilkan ringkasan pemakaian pada periode yang dipilih.
    @endif
  </section>

  @if($summary)
    <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-4">
      <div class="section-card">
        <div class="metric-label">Bahan Baku Lama</div>
        <div class="stat-value text-[1.35rem]">{{ number_format($summary['opening'], 2, ',', '.') }}</div>
      </div>
      <div class="section-card">
        <div class="metric-label">Pembelian</div>
        <div class="stat-value text-[1.35rem]">{{ number_format($summary['purchases'], 2, ',', '.') }}</div>
      </div>
      <div class="section-card">
        <div class="metric-label">Sisa Stok</div>
        <div class="stat-value text-[1.35rem]">{{ number_format($summary['ending'], 2, ',', '.') }}</div>
      </div>
      <div class="section-card">
        <div class="metric-label">Pemakaian</div>
        <div class="text-xl font-semibold text-amber-700">{{ number_format($summary['usage'], 2, ',', '.') }}</div>
      </div>
    </div>

    <section class="table-shell mt-6">
      <div class="table-head">
        Rincian Pemakaian {{ $selectedItem?->name ?? '-' }}@if($selectedItem?->unit) ({{ $selectedItem->unit }})@endif
      </div>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Jenis</th>
              <th class="text-right">Total Cost</th>
              <th>Keterangan</th>
            </tr>
          </thead>
          <tbody>
            @foreach($detailRows as $row)
              @if($row['kind'] === 'entry')
                <tr>
                  <td>{{ $row['date_text'] ?? '-' }}</td>
                  <td>{{ $row['type'] ?? '-' }}</td>
                  <td class="text-right">{{ number_format((float) ($row['value'] ?? 0), 2, ',', '.') }}</td>
                  <td>{{ $row['notes'] ?? '' }}</td>
                </tr>
              @elseif($row['kind'] === 'subtotal')
                <tr class="bg-slate-50 font-semibold text-slate-900">
                  <td colspan="2">{{ $row['label'] }}</td>
                  <td class="text-right">{{ number_format((float) ($row['value'] ?? 0), 2, ',', '.') }}</td>
                  <td>{{ $row['notes'] ?? '' }}</td>
                </tr>
              @else
                <tr class="bg-amber-50 font-bold text-slate-900">
                  <td colspan="2">{{ $row['label'] }}</td>
                  <td class="text-right">{{ number_format((float) ($row['value'] ?? 0), 2, ',', '.') }}</td>
                  <td>{{ $row['notes'] ?? '' }}</td>
                </tr>
              @endif
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  @endif
@endsection

