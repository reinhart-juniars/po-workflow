@extends('layouts.accountingapp', ['title' => 'Adjustment Neraca'])

@section('content')
    @php
        // Kartu form tertutup bawaan (tabel langsung terlihat, seperti panel
        // Inventory); terbuka lewat tombolnya atau saat validasi gagal.
        $activeTab = $errors->any() ? 'form' : '';
        $formatCurrency = function (float $amount): string {
            $prefix = $amount < 0 ? '(Rp ' : 'Rp ';
            $suffix = $amount < 0 ? ')' : '';

            return $prefix . number_format(abs($amount), 0, ',', '.') . $suffix;
        };
    @endphp

    <section class="dashboard-hero">
        <div class="page-toolbar">
            <div>
                <h1 class="dashboard-hero-title">Adjustment Neraca</h1>
                <p class="dashboard-hero-subtitle">
                    Input penyesuaian historis untuk neraca.
                </p>
            </div>
        </div>
    </section>

    <div class="mt-4 notice-soft-amber">
        Gunakan fitur ini untuk koreksi setup historis. <br>
        Jangan isi saldo akhir full bulanan jika yang dibutuhkan hanya koreksi selisih.
    </div>

    <div class="tab-card-shell js-tab-card mt-4" data-active-tab="{{ $activeTab }}">
        <div class="panel-head">
            <div class="inline-flex items-center gap-1 rounded-lg bg-gray-100 p-1">
                <button type="button" data-tab-trigger="form" class="tab-trigger text-gray-600 hover:text-gray-800">
                    Tambah Adjustment
                </button>
            </div>
        </div>

        <div class="p-5 @unless($activeTab === 'form') hidden @endunless" data-tab-panel="form">
            <form method="POST" action="{{ route('accountingapp.balance-sheet-adjustments.store') }}"
                class="grid grid-cols-1 gap-3 md:grid-cols-5">
                @csrf

                <div>
                    <label class="form-label">Tanggal Adjustment</label>
                    <input type="date" name="adjustment_date" value="{{ old('adjustment_date', now()->toDateString()) }}"
                        class="form-control" required>
                </div>

                <div>
                    <label class="form-label">Grup Neraca</label>
                    <select name="group" class="form-control" required>
                        <option value="">Pilih Grup</option>
                        @foreach ($groupOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('group') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="form-label">Nama Pos</label>
                    <input type="text" name="label" value="{{ old('label') }}" class="form-control"
                        placeholder="Contoh: Koreksi saldo bank Januari" required>
                </div>

                <div>
                    <label class="form-label">Nominal Selisih</label>
                    <input type="number" name="amount" step="0.01" value="{{ old('amount') }}" class="form-control"
                        required>
                    <div class="field-help">Boleh negatif untuk mengurangi saldo.</div>
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

    </div>


    <div class="table-card mt-6">
        <x-table-toolbar title="Daftar Adjustment Neraca" :action="route('accountingapp.balance-sheet-adjustments.index')"
            :filters="[
                ['type' => 'date-range', 'label' => 'Periode', 'from' => 'date_from', 'to' => 'date_to',
                 'value' => [$dateFrom, $dateTo]],
                ['type' => 'select', 'name' => 'group', 'label' => 'Grup',
                 'options' => $groupOptions, 'value' => $selectedGroup, 'placeholder' => 'Semua grup'],
            ]" />

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Tanggal</th>
                        <th class="px-4 py-2 text-left">Grup</th>
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
                                {{ $groupOptions[$adjustment->account_group] ?? $adjustment->account_group }}</td>
                            <td class="px-4 py-2">{{ $adjustment->label }}</td>
                            <td class="px-4 py-2">{{ $adjustment->notes ?: '-' }}</td>
                            <td class="px-4 py-2 text-right">{{ $formatCurrency((float) $adjustment->amount) }}
                            </td>
                            <td class="px-4 py-2">{{ $adjustment->creator->name ?? '-' }}</td>
                            <td class="px-4 py-2">
                                <div class="row-actions">
                                    <x-row-action kind="edit" :href="route('accountingapp.balance-sheet-adjustments.edit', $adjustment)" />
                                    <x-row-action kind="delete" :action="route('accountingapp.balance-sheet-adjustments.destroy', $adjustment)"
                                        confirm="Hapus adjustment neraca ini?" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-gray-500">
                                Belum ada adjustment neraca.
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

    <script>
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

                // Klik tab yang sedang terbuka menutupnya kembali.
                triggers.forEach((trigger) => {
                    trigger.addEventListener('click', () => {
                        activateTab(trigger.classList.contains('bg-white') ? null : trigger.dataset.tabTrigger);
                    });
                });

                activateTab(card.dataset.activeTab || null);
            });
        })();
    </script>
@endsection
