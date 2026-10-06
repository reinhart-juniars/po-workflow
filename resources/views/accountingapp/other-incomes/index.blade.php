@extends('layouts.accountingapp', ['title' => 'Pemasukan Lain'])

@section('content')
  @php
    // Kartu form tertutup bawaan (tabel langsung terlihat, seperti panel
    // Inventory); terbuka lewat tombolnya atau saat validasi gagal.
    $activeTab = '';
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
      </div>
    </div>

    <div class="p-5 @unless($activeTab === 'form') hidden @endunless" data-tab-panel="form">
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

  </div>

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
    <x-table-toolbar title="Daftar Pemasukan Lain" :action="route('accountingapp.other-incomes.index')"
      :filters="[
        ['type' => 'date-range', 'label' => 'Periode', 'from' => 'date_from', 'to' => 'date_to',
         'value' => [$dateFrom, $dateTo]],
        ['type' => 'select', 'name' => 'income_category_id', 'label' => 'Kategori',
         'options' => $incomeCategories->pluck('name', 'id'), 'value' => $categoryId, 'placeholder' => 'Semua kategori'],
        ['type' => 'select', 'name' => 'cash_account_id', 'label' => 'Akun Kas',
         'options' => $cashAccounts->mapWithKeys(fn ($a) => [$a->id => $a->name.' ('.($a->type === 'bank' ? 'Bank' : 'Tunai').')']),
         'value' => $cashAccountId, 'placeholder' => 'Semua akun kas'],
      ]" />

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
                <div class="row-actions">
                  <x-row-action kind="edit" :href="route('accountingapp.other-incomes.edit', $otherIncome->id)" />
                  <x-row-action kind="delete" :action="route('accountingapp.other-incomes.destroy', $otherIncome->id)"
                                confirm="Hapus data pemasukan lain ini?" />
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

        // Klik tab yang sedang terbuka menutupnya kembali.
        triggers.forEach((button) => {
          button.addEventListener('click', () => {
            setActive(button.getAttribute('aria-selected') === 'true' ? null : button.dataset.tabTrigger);
          });
        });

        setActive(card.dataset.activeTab || null);
      });

    })();
  </script>
@endsection




