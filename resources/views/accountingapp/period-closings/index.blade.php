@extends('layouts.accountingapp', ['title' => 'Status Periode'])

@section('content')
    @php
        // Kartu form tertutup bawaan (tabel langsung terlihat, seperti panel
        // Inventory); terbuka lewat tombolnya atau saat validasi gagal.
        $activeTab = '';
        foreach (['period_month', 'period_year', 'notes'] as $field) {
            if ($errors->has($field)) {
                $activeTab = 'form';
                break;
            }
        }

        $monthOptions = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];
    @endphp

    <h1 class="text-2xl font-bold mb-4">Status Periode</h1>

    @if ($previousOpenPeriodWarning)
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <strong>Warning:</strong> {{ $previousOpenPeriodWarning['message'] }}
            {{-- Tutup periode <strong>{{ $previousOpenPeriodWarning['previous_period_label'] }}</strong> agar warning ini hilang. --}}
        </div>
    @endif

    {{-- <div class="mb-4 notice-soft-amber">
        Penutupan periode di halaman ini bersifat <strong>manual</strong>, bukan otomatis akhir bulan.
        Setelah periode ditutup, transaksi pada bulan tersebut akan dianggap locked dan koreksi hanya bisa dilakukan owner.
    </div> --}}

    <div class="tab-card-shell js-tab-card" data-active-tab="{{ $activeTab }}">
        <div class="panel-head">
            <div class="inline-flex items-center gap-1 rounded-lg bg-gray-100 p-1">
                <button type="button" data-tab-trigger="form" class="tab-trigger text-gray-600 hover:text-gray-800">
                    Tutup Periode
                </button>
            </div>
        </div>

        <div class="p-5 @unless($activeTab === 'form') hidden @endunless" data-tab-panel="form">
            <form method="POST" action="{{ route('accountingapp.period-closings.store') }}"
                class="grid grid-cols-1 md:grid-cols-4 gap-3">
                @csrf

                <div>
                    <label class="form-label">Bulan</label>
                    <select name="period_month" id="period_month" class="form-control" required>
                        <option value="">Pilih Bulan</option>
                        @foreach ($monthOptions as $monthNumber => $monthLabel)
                            <option value="{{ $monthNumber }}" @selected((int) old('period_month') === $monthNumber)>
                                {{ $monthLabel }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="form-label">Tahun</label>
                    <input type="number" name="period_year" id="period_year" min="2000" max="2100"
                        value="{{ old('period_year', now()->year) }}" class="form-control" required>
                </div>

                <div class="md:col-span-2">
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" rows="2" class="form-control">{{ old('notes') }}</textarea>
                </div>

                <div id="period_closing_hint"
                    class="md:col-span-4 hidden rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                </div>

                <div class="md:col-span-4 flex justify-end">
                    <button type="submit" id="period_closing_submit" class="btn-primary">
                        Tutup Periode
                    </button>
                </div>
            </form>
        </div>

    </div>


    <div class="table-card mt-6">
        <x-table-toolbar title="Riwayat Status Periode" :action="route('accountingapp.period-closings.index')"
            :filters="[
                ['type' => 'select', 'name' => 'year', 'label' => 'Tahun', 'required' => true, 'removable' => false,
                 'options' => collect($yearOptions)->mapWithKeys(fn ($y) => [$y => $y]), 'value' => $selectedYear],
                ['type' => 'select', 'name' => 'month', 'label' => 'Bulan', 'placeholder' => 'Semua bulan',
                 'options' => $monthOptions, 'value' => $selectedMonth ?? null],
            ]" />

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left px-4 py-2">Periode</th>
                        <th class="text-left px-4 py-2">Ditutup Pada</th>
                        <th class="text-left px-4 py-2">Ditutup Oleh</th>
                        <th class="text-left px-4 py-2">Catatan</th>
                        <th class="text-left px-4 py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($periodClosings as $periodClosing)
                        <tr class="border-t">
                            <td class="px-4 py-2">
                                {{ $monthOptions[(int) $periodClosing->period_month] ?? $periodClosing->period_month }}
                                {{ $periodClosing->period_year }}
                            </td>
                            <td class="px-4 py-2">
                                {{ optional($periodClosing->closed_at)->format('d-m-Y H:i') ?? '-' }}</td>
                            <td class="px-4 py-2">{{ $periodClosing->closer->name ?? '-' }}</td>
                            <td class="px-4 py-2">{{ $periodClosing->notes ?: '-' }}</td>
                            <td class="px-4 py-2">
                                @if (auth()->user()
                                        ?->hasAnyRole(['owner', 'superadmin']))
                                    <form method="POST"
                                        action="{{ route('accountingapp.period-closings.destroy', $periodClosing) }}"
                                        onsubmit="return confirm('Buka kembali periode ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-link-danger">Buka Kembali</button>
                                    </form>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                                Belum ada periode yang ditutup.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4">
            {{ $periodClosings->links() }}
        </div>
    </div>

    <script>
        (() => {
            const tabCards = document.querySelectorAll('.js-tab-card');
            const yearInput = document.getElementById('period_year');
            const monthSelect = document.getElementById('period_month');
            const submitButton = document.getElementById('period_closing_submit');
            const hintBox = document.getElementById('period_closing_hint');
            const closedPeriodsByYear = @json($closedPeriodsByYear);
            const monthOptions = @json($monthOptions);

            tabCards.forEach((card) => {
                const defaultTab = card.dataset.activeTab || null;
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

                activateTab(defaultTab);
            });

            if (!yearInput || !monthSelect || !submitButton || !hintBox) {
                return;
            }

            const syncClosedPeriods = () => {
                const selectedYear = yearInput.value;
                const closedMonths = closedPeriodsByYear[selectedYear] || [];
                const selectedMonth = Number(monthSelect.value || 0);

                Array.from(monthSelect.options).forEach((option) => {
                    if (!option.value) {
                        return;
                    }

                    const optionMonth = Number(option.value);
                    const isClosed = closedMonths.includes(optionMonth);
                    option.disabled = isClosed;
                    option.hidden = isClosed;
                });

                const isDuplicate = selectedMonth > 0 && closedMonths.includes(selectedMonth);

                hintBox.classList.toggle('hidden', !isDuplicate);
                submitButton.disabled = isDuplicate;
                submitButton.classList.toggle('opacity-60', isDuplicate);
                submitButton.classList.toggle('cursor-not-allowed', isDuplicate);

                if (isDuplicate) {
                    hintBox.textContent =
                        `Periode ${monthOptions[selectedMonth]} ${selectedYear} sudah ditutup. Pilih bulan lain atau buka kembali periode tersebut dari riwayat.`;
                } else {
                    hintBox.textContent = '';
                }
            };

            yearInput.addEventListener('input', syncClosedPeriods);
            monthSelect.addEventListener('change', syncClosedPeriods);
            syncClosedPeriods();
        })();
    </script>
@endsection
