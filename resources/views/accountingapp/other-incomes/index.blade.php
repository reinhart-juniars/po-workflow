@extends('layouts.accountingapp', ['title' => 'Pemasukan Lain'])

@section('content')
  @php
    $activeTab = 'filter';
    $formFields = ['income_date', 'income_category_id', 'cash_account_id', 'amount', 'description', 'adjustment_note'];
    foreach ($formFields as $field) {
        if ($errors->has($field)) {
            $activeTab = 'form';
            break;
        }
    }
  @endphp

  <h1 class="text-2xl font-bold mb-4">Pemasukan Lain</h1>

  <div class="mb-4 {{ $rangePeriodStatus['has_closed_periods'] ? 'notice-soft-amber' : 'notice-soft-emerald' }}">
    <strong>Status periode:</strong> {{ $rangePeriodStatus['message'] }}
  </div>

  <div class="tab-card-shell js-tab-card" data-active-tab="{{ $activeTab }}">
    <div class="panel-head">
      <div class="inline-flex items-center gap-1 rounded-lg bg-gray-100 p-1">
        <button
          type="button"
          data-tab-trigger="form"
          class="tab-trigger text-gray-600 hover:text-gray-800"
        >
          Tambah Pemasukan Lain
        </button>
        <button
          type="button"
          data-tab-trigger="filter"
          class="tab-trigger text-gray-600 hover:text-gray-800"
        >
          Filter Data
        </button>
      </div>
    </div>

    <div class="p-5" data-tab-panel="form">
      <form method="POST" action="{{ route('accountingapp.other-incomes.store') }}"
            class="grid grid-cols-1 md:grid-cols-4 gap-3">
        @csrf

        <div>
          <label class="form-label">Tanggal</label>
          <input type="date" name="income_date" value="{{ old('income_date', now()->toDateString()) }}"
                 class="form-control" required>
        </div>

        <div>
          <label class="form-label">Kategori Pemasukan</label>
          <select name="income_category_id" class="form-control" required>
            <option value="">Pilih Kategori</option>
            @foreach ($incomeCategories as $incomeCategory)
              <option value="{{ $incomeCategory->id }}" @selected((string) old('income_category_id') === (string) $incomeCategory->id)>
                {{ $incomeCategory->name }}
              </option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="form-label">Cash Account</label>
          <select name="cash_account_id" class="form-control" required>
            <option value="">Pilih Cash Account</option>
            @foreach ($cashAccounts as $cashAccount)
              <option value="{{ $cashAccount->id }}" @selected((string) old('cash_account_id') === (string) $cashAccount->id)>
                {{ $cashAccount->name }} ({{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
              </option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="form-label">Nominal</label>
          <input type="number" name="amount" min="1" step="0.01" value="{{ old('amount') }}"
                 class="form-control" required>
        </div>

        <div class="md:col-span-4">
          <label class="form-label">Keterangan</label>
          <textarea name="description" rows="2"
                    class="form-control">{{ old('description') }}</textarea>
        </div>
        <div class="md:col-span-4">
          <label class="form-label">Catatan Koreksi</label>
          <textarea name="adjustment_note" rows="2"
                    class="form-control"
                    placeholder="Wajib diisi jika tanggal masuk ke periode yang sudah ditutup.">{{ old('adjustment_note') }}</textarea>
        </div>

        <div class="md:col-span-4 flex justify-end">
          <button type="submit" class="btn-primary">
            Simpan Pemasukan Lain
          </button>
        </div>
      </form>
    </div>

    <div class="p-5 hidden" data-tab-panel="filter">
      <form method="GET" action="{{ route('accountingapp.other-incomes.index') }}"
            class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div class="md:col-span-4 flex flex-wrap gap-2">
          <button type="button" class="chip-filter js-date-preset" data-form-scope="other-incomes-filter" data-preset="this_month">Bulan Ini</button>
          <button type="button" class="chip-filter js-date-preset" data-form-scope="other-incomes-filter" data-preset="last_month">Bulan Lalu</button>
          <button type="button" class="chip-filter js-date-preset" data-form-scope="other-incomes-filter" data-preset="this_year">Tahun Berjalan</button>
        </div>

        <div>
          <label class="form-label">Dari Tanggal</label>
          <input type="date" name="date_from" value="{{ $dateFrom->toDateString() }}"
                 data-form-scope="other-incomes-filter" data-role="date-from"
                 class="form-control">
        </div>

        <div>
          <label class="form-label">Sampai Tanggal</label>
          <input type="date" name="date_to" value="{{ $dateTo->toDateString() }}"
                 data-form-scope="other-incomes-filter" data-role="date-to"
                 class="form-control">
        </div>

        <div>
          <label class="form-label">Kategori Pemasukan</label>
          <select name="income_category_id" class="form-control">
            <option value="">Semua Kategori</option>
            @foreach ($incomeCategories as $incomeCategory)
              <option value="{{ $incomeCategory->id }}" @selected((string) $categoryId === (string) $incomeCategory->id)>
                {{ $incomeCategory->name }}
              </option>
            @endforeach
          </select>
        </div>

        <div>
          <label class="form-label">Cash Account</label>
          <select name="cash_account_id" class="form-control">
            <option value="">Semua Cash Account</option>
            @foreach ($cashAccounts as $cashAccount)
              <option value="{{ $cashAccount->id }}" @selected((string) $cashAccountId === (string) $cashAccount->id)>
                {{ $cashAccount->name }} ({{ $cashAccount->type === 'bank' ? 'Bank' : 'Tunai' }})
              </option>
            @endforeach
          </select>
        </div>

        <div class="md:col-span-4 flex justify-end">
          <button class="btn-secondary">Filter</button>
        </div>
      </form>
      <div class="section-card mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <div class="metric-label">Total Pemasukan Lain</div>
          <div class="metric-value text-emerald-700">
            Rp {{ number_format($totalOtherIncome, 0, ',', '.') }}
          </div>
        </div>
        <div>
          <div class="metric-label">Jumlah Transaksi</div>
          <div class="metric-value">
            {{ number_format($otherIncomeCount, 0, ',', '.') }}
          </div>
        </div>
      </div>

      <div class="table-card mt-6">
        <div class="table-card-head">Daftar Pemasukan Lain</div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="text-left px-4 py-2">Tanggal</th>
                <th class="text-left px-4 py-2">Kategori</th>
                <th class="text-left px-4 py-2">Cash Account</th>
                <th class="text-left px-4 py-2">Keterangan</th>
                <th class="text-right px-4 py-2">Nominal</th>
                <th class="text-left px-4 py-2">Input Oleh</th>
                <th class="text-left px-4 py-2">Update Terakhir</th>
                <th class="text-left px-4 py-2">Aksi</th>
              </tr>
            </thead>
            <tbody>
              @forelse($otherIncomes as $otherIncome)
                <tr class="border-t">
                  <td class="px-4 py-2">{{ $otherIncome->income_date->format('d-m-Y') }}</td>
                  <td class="px-4 py-2">{{ $otherIncome->category->name ?? '-' }}</td>
                  <td class="px-4 py-2">{{ $otherIncome->cashAccount->name ?? '-' }}</td>
                  <td class="px-4 py-2">
                    <div>{{ $otherIncome->description ?: '-' }}</div>
                    <div class="mt-1 flex flex-wrap gap-2">
                      @if($otherIncome->is_adjustment)
                        <span class="badge-soft-amber">
                          Koreksi
                        </span>
                      @endif
                      @if($otherIncome->period_closed)
                        <span class="badge-soft-slate">
                          Periode Tertutup
                        </span>
                      @endif
                    </div>
                  </td>
                  <td class="px-4 py-2 text-right">Rp {{ number_format($otherIncome->amount, 0, ',', '.') }}</td>
                  <td class="px-4 py-2">{{ $otherIncome->creator->name ?? '-' }}</td>
                  <td class="px-4 py-2">{{ $otherIncome->updater->name ?? '-' }}</td>
                  <td class="px-4 py-2">
                    <div class="flex items-center gap-3">
                      <a href="{{ route('accountingapp.other-incomes.edit', $otherIncome->id) }}"
                         class="btn-link">
                        Edit
                      </a>
                      <form method="POST" action="{{ route('accountingapp.other-incomes.destroy', $otherIncome->id) }}"
                            onsubmit="return confirm('Hapus data pemasukan lain ini?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-link-danger">
                          Hapus
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="8" class="px-4 py-6 text-center text-gray-500">
                    Belum ada data pemasukan lain.
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        <div class="p-4">
          {{ $otherIncomes->links() }}
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const tabCards = document.querySelectorAll('.js-tab-card');
      if (!tabCards.length) return;

      tabCards.forEach((card) => {
        const triggers = card.querySelectorAll('[data-tab-trigger]');
        const panels = card.querySelectorAll('[data-tab-panel]');
        const activeClasses = ['bg-white', 'text-indigo-700', 'shadow-sm'];
        const inactiveClasses = ['text-gray-600', 'hover:text-gray-800'];

        const setActive = (target) => {
          triggers.forEach((button) => {
            const isActive = button.dataset.tabTrigger === target;
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');

            if (isActive) {
              button.classList.add(...activeClasses);
              button.classList.remove(...inactiveClasses);
            } else {
              button.classList.remove(...activeClasses);
              button.classList.add(...inactiveClasses);
            }
          });

          panels.forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.tabPanel !== target);
          });
        };

        triggers.forEach((button) => {
          button.addEventListener('click', () => setActive(button.dataset.tabTrigger));
        });

        setActive(card.dataset.activeTab || (triggers[0] && triggers[0].dataset.tabTrigger));
      });

      const applyPreset = (scope, preset) => {
        const fromInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-from"]`);
        const toInput = document.querySelector(`[data-form-scope="${scope}"][data-role="date-to"]`);

        if (!fromInput || !toInput) return;

        const now = new Date();
        const pad = (value) => String(value).padStart(2, '0');
        const format = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

        let fromDate = new Date(now.getFullYear(), now.getMonth(), 1);
        let toDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);

        if (preset === 'last_month') {
          fromDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
          toDate = new Date(now.getFullYear(), now.getMonth(), 0);
        } else if (preset === 'this_year') {
          fromDate = new Date(now.getFullYear(), 0, 1);
          toDate = new Date(now.getFullYear(), 11, 31);
        }

        fromInput.value = format(fromDate);
        toInput.value = format(toDate);
      };

      document.querySelectorAll('.js-date-preset').forEach((button) => {
        button.addEventListener('click', () => applyPreset(button.dataset.formScope, button.dataset.preset));
      });
    })();
  </script>
@endsection




