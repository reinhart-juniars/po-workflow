@extends('layouts.accountingapp', ['title' => 'Adjustment Laba Rugi'])

@section('content')
    @php
        $activeTab = $errors->any() ? 'form' : 'list';
        $formatCurrency = function (float $amount): string {
            $prefix = $amount < 0 ? '(Rp ' : 'Rp ';
            $suffix = $amount < 0 ? ')' : '';

            return $prefix . number_format(abs($amount), 0, ',', '.') . $suffix;
        };
    @endphp

    <section class="dashboard-hero">
        <div class="page-toolbar">
            <div>
                <h1 class="dashboard-hero-title">Adjustment Laba Rugi</h1>
                <p class="dashboard-hero-subtitle">
                    Input penyesuaian historis untuk laporan laba rugi.
                </p>
            </div>
        </div>
    </section>

    <div class="mt-4 notice-soft-amber">
        Gunakan fitur ini untuk koreksi bulan historis yang sudah lewat. <br>
        Nominal positif pada HPP atau beban operasional akan menambah beban dan mengurangi laba.
    </div>

    <div class="tab-card-shell js-tab-card mt-4" data-active-tab="{{ $activeTab }}">
        <div class="panel-head">
            <div class="inline-flex items-center gap-1 rounded-lg bg-gray-100 p-1">
                <button type="button" data-tab-trigger="form" class="tab-trigger text-gray-600 hover:text-gray-800">
                    Tambah Adjustment
                </button>
                <button type="button" data-tab-trigger="list" class="tab-trigger text-gray-600 hover:text-gray-800">
                    Daftar Data
                </button>
            </div>
        </div>

        <div class="p-5" data-tab-panel="form">
            <form method="POST" action="{{ route('accountingapp.profit-loss-adjustments.store') }}"
                class="grid grid-cols-1 gap-3 md:grid-cols-5">
                @csrf

                <div>
                    <label class="form-label">Tanggal Adjustment</label>
                    <input type="date" name="adjustment_date"
                        value="{{ old('adjustment_date', $maxAdjustmentDate) }}" max="{{ $maxAdjustmentDate }}"
                        class="form-control" required>
                    <div class="field-help">Maksimal tanggal {{ \Carbon\Carbon::parse($maxAdjustmentDate)->format('d-m-Y') }}.</div>
                </div>

                <div>
                    <label class="form-label">Grup Laba Rugi</label>
                    <select name="group" class="form-control" required data-pl-group-select>
                        <option value="">Pilih Grup</option>
                        @foreach ($groupOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('group') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div data-pl-expense-category-wrapper style="display: none;">
                    <label class="form-label">Kategori Beban</label>
                    <select name="expense_category_id" class="form-control">
                        <option value="">Tidak terkait kategori</option>
                        @foreach ($expenseCategories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('expense_category_id') === (string) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="field-help">Pilih kategori untuk merge ke baris kategori di laporan. Kosongkan untuk baris terpisah.</div>
                </div>

                <div class="md:col-span-2">
                    <label class="form-label">Nama Pos</label>
                    <input type="text" name="label" value="{{ old('label') }}" class="form-control"
                        placeholder="Contoh: Koreksi HPP Februari" required>
                </div>

                <div>
                    <label class="form-label">Nominal Selisih</label>
                    <input type="number" name="amount" step="0.01" value="{{ old('amount') }}" class="form-control"
                        required>
                    <div class="field-help">Boleh negatif untuk membalik efek grup.</div>
                </div>

                <div class="md:col-span-5">
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" rows="2" class="form-control" placeholder="Sumber data atau alasan koreksi">{{ old('notes') }}</textarea>
                </div>

                <div class="md:col-span-5 flex justify-end">
                    <button type="submit" class="btn-primary">Simpan Adjustment</button>
                </div>
            </form>
        </div>

        <div class="hidden p-5" data-tab-panel="list">
            <form method="GET" action="{{ route('accountingapp.profit-loss-adjustments.index') }}"
                class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <div>
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control">
                </div>

                <div>
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control">
                </div>

                <div>
                    <label class="form-label">Grup</label>
                    <select name="group" class="form-control">
                        <option value="">Semua Grup</option>
                        @foreach ($groupOptions as $value => $label)
                            <option value="{{ $value }}" @selected($selectedGroup === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit" class="btn-secondary w-full">Filter</button>
                </div>
            </form>

            <div class="table-card mt-6">
                <div class="table-card-head">Daftar Adjustment Laba Rugi</div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">Tanggal</th>
                                <th class="px-4 py-2 text-left">Grup</th>
                                <th class="px-4 py-2 text-left">Kategori</th>
                                <th class="px-4 py-2 text-left">Nama Pos</th>
                                <th class="px-4 py-2 text-left">Catatan</th>
                                <th class="px-4 py-2 text-right">Nominal</th>
                                <th class="px-4 py-2 text-left">Input Oleh</th>
                                <th class="px-4 py-2 text-left">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($adjustments as $adjustment)
                                <tr class="border-t">
                                    <td class="px-4 py-2">{{ $adjustment->adjustment_date->format('d-m-Y') }}</td>
                                    <td class="px-4 py-2">
                                        {{ $groupOptions[$adjustment->statement_group] ?? $adjustment->statement_group }}</td>
                                    <td class="px-4 py-2">{{ $adjustment->expenseCategory?->name ?: '-' }}</td>
                                    <td class="px-4 py-2">{{ $adjustment->label }}</td>
                                    <td class="px-4 py-2">{{ $adjustment->notes ?: '-' }}</td>
                                    <td class="px-4 py-2 text-right">{{ $formatCurrency((float) $adjustment->amount) }}
                                    </td>
                                    <td class="px-4 py-2">{{ $adjustment->creator->name ?? '-' }}</td>
                                    <td class="px-4 py-2">
                                        <div class="flex items-center gap-3">
                                            <a href="{{ route('accountingapp.profit-loss-adjustments.edit', $adjustment) }}"
                                                class="btn-link">Edit</a>
                                        <form method="POST"
                                            action="{{ route('accountingapp.profit-loss-adjustments.destroy', $adjustment) }}"
                                            onsubmit="return confirm('Hapus adjustment laba rugi ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-link-danger">Hapus</button>
                                        </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-6 text-center text-gray-500">
                                        Belum ada adjustment laba rugi.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="p-4">
                    {{ $adjustments->links() }}
                </div>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const groupSelect = document.querySelector('[data-pl-group-select]');
            const wrapper = document.querySelector('[data-pl-expense-category-wrapper]');
            if (groupSelect && wrapper) {
                const toggle = () => {
                    wrapper.style.display = groupSelect.value === 'operating_expense' ? '' : 'none';
                    if (groupSelect.value !== 'operating_expense') {
                        const select = wrapper.querySelector('select');
                        if (select) {
                            select.value = '';
                        }
                    }
                };
                groupSelect.addEventListener('change', toggle);
                toggle();
            }
        })();

        (() => {
            document.querySelectorAll('.js-tab-card').forEach((card) => {
                const triggers = card.querySelectorAll('[data-tab-trigger]');
                const panels = card.querySelectorAll('[data-tab-panel]');

                const activateTab = (name) => {
                    triggers.forEach((trigger) => {
                        const isActive = trigger.dataset.tabTrigger === name;
                        trigger.classList.toggle('bg-white', isActive);
                        trigger.classList.toggle('text-gray-900', isActive);
                        trigger.classList.toggle('shadow-sm', isActive);
                        trigger.classList.toggle('text-gray-600', !isActive);
                    });

                    panels.forEach((panel) => {
                        panel.classList.toggle('hidden', panel.dataset.tabPanel !== name);
                    });
                };

                triggers.forEach((trigger) => {
                    trigger.addEventListener('click', () => activateTab(trigger.dataset.tabTrigger));
                });

                activateTab(card.dataset.activeTab || 'list');
            });
        })();
    </script>
@endsection
