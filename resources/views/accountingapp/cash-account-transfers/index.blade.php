@extends('layouts.accountingapp', ['title' => 'Transfer Antar Akun'])

@section('content')
  <section class="dashboard-hero">
    <div class="page-toolbar">
      <div>
        <h1 class="dashboard-hero-title">Transfer Antar Akun Kas</h1>
        <p class="dashboard-hero-subtitle">
          Catat pemindahan saldo antar akun kas (misalnya dari Bank ke Tunai). Transfer ini bersifat internal:
          tidak masuk pendapatan, tidak masuk pengeluaran, dan tidak mempengaruhi Laba Rugi.
        </p>
      </div>
    </div>
  </section>

  <section class="app-card mt-4 p-4">
    <h2 class="section-title">Catat Transfer Baru</h2>
    <p class="section-subtitle">Saldo akun sumber akan berkurang dan saldo akun tujuan akan bertambah sesuai nominal.</p>

    <form method="POST" action="{{ route('accountingapp.cash-account-transfers.store') }}" class="form-grid mt-4">
      @csrf
      <div>
        <label class="form-label">Tanggal Transfer</label>
        <input type="date" name="transfer_date" value="{{ old('transfer_date', now()->toDateString()) }}"
               max="{{ now()->toDateString() }}" class="form-control" required>
        @error('transfer_date') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
      </div>

      <div>
        <label class="form-label">Dari Akun</label>
        <select name="from_cash_account_id" class="form-control" required>
          <option value="">Pilih Akun Sumber</option>
          @foreach($cashAccounts as $account)
            <option value="{{ $account->id }}" @selected(old('from_cash_account_id') == $account->id)>
              {{ $account->name }} ({{ $account->type === 'bank' ? 'Bank' : 'Tunai' }})
            </option>
          @endforeach
        </select>
        @error('from_cash_account_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
      </div>

      <div>
        <label class="form-label">Ke Akun</label>
        <select name="to_cash_account_id" class="form-control" required>
          <option value="">Pilih Akun Tujuan</option>
          @foreach($cashAccounts as $account)
            <option value="{{ $account->id }}" @selected(old('to_cash_account_id') == $account->id)>
              {{ $account->name }} ({{ $account->type === 'bank' ? 'Bank' : 'Tunai' }})
            </option>
          @endforeach
        </select>
        @error('to_cash_account_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
      </div>

      <div>
        <label class="form-label">Nominal</label>
        <input type="number" name="amount" value="{{ old('amount') }}" min="1" step="0.01"
               class="form-control" required placeholder="0">
        @error('amount') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
      </div>

      <div class="md:col-span-2">
        <label class="form-label">Catatan (opsional)</label>
        <input type="text" name="notes" value="{{ old('notes') }}" class="form-control" maxlength="255"
               placeholder="Misal: Tarik tunai untuk operasional">
        @error('notes') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
      </div>

      <div class="md:col-span-2 flex justify-end">
        <button type="submit" class="btn-primary">Simpan Transfer</button>
      </div>
    </form>
  </section>

  <section class="table-shell mt-4">
    <div class="table-head flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <span>Riwayat Transfer</span>
      <form method="GET" action="{{ route('accountingapp.cash-account-transfers.index') }}"
            class="flex flex-wrap items-end gap-2">
        <div>
          <label class="form-label text-xs">Dari</label>
          <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control">
        </div>
        <div>
          <label class="form-label text-xs">Sampai</label>
          <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control">
        </div>
        <button class="btn-secondary">Filter</button>
        <a href="{{ route('accountingapp.cash-account-transfers.index') }}" class="btn-ghost">Reset</a>
      </form>
    </div>

    @if($transfers->isNotEmpty())
      <div class="px-4 py-3 text-sm text-slate-600 border-b border-slate-100">
        Total transfer dalam filter: <strong>Rp {{ number_format((float) $totalTransferred, 0, ',', '.') }}</strong>
        ({{ $transfers->total() }} transaksi)
      </div>
    @endif

    <div class="data-table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Dari Akun</th>
            <th>Ke Akun</th>
            <th class="text-right">Nominal</th>
            <th>Catatan</th>
            <th>Pencatat</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($transfers as $transfer)
            <tr>
              <td>{{ optional($transfer->transfer_date)->format('d-m-Y') }}</td>
              <td>{{ $transfer->fromCashAccount->name ?? '-' }}</td>
              <td>{{ $transfer->toCashAccount->name ?? '-' }}</td>
              <td class="text-right font-semibold">Rp {{ number_format((float) $transfer->amount, 0, ',', '.') }}</td>
              <td class="text-xs text-slate-600">{{ $transfer->notes ?: '-' }}</td>
              <td class="text-xs text-slate-500">{{ $transfer->creator->name ?? '-' }}</td>
              <td>
                <form method="POST"
                      action="{{ route('accountingapp.cash-account-transfers.destroy', $transfer) }}"
                      onsubmit="return confirm('Hapus catatan transfer ini?')">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="btn-link-danger">Hapus</button>
                </form>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="7" class="py-8 text-center text-sm text-slate-500">
                Belum ada catatan transfer antar akun.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4">{{ $transfers->links() }}</div>
  </section>
@endsection
