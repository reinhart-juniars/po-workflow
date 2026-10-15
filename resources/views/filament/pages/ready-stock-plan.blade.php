@php
    $stock = $this->getStock();
    $demand = $this->getDemand();
    $shopping = $this->getShopping();
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $tgl = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->translatedFormat('D, d M Y') : '-';
    $requisitionLabels = \App\Models\Requisition::statusOptions();
    $days = $this->getDays();
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <div class="sh-kpi" style="--cols: 4">
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Disiapkan</p>
            <p class="sh-kpi-value">{{ $qty($stock->sum('planned')) }}</p>
            <p class="sh-kpi-note">porsi di Rencana Masak</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Terdistribusi ke PO</p>
            <p class="sh-kpi-value is-ok">{{ $qty($stock->sum('distributed')) }}</p>
            <p class="sh-kpi-note">dicentang "Ambil dari stok siap"</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Sisa</p>
            <p @class(['sh-kpi-value', 'is-bad' => $stock->sum('remaining') > 0])>{{ $qty($stock->sum('remaining')) }}</p>
            <p class="sh-kpi-note">belum masuk PO; jadi Barang Sisa bila tidak terdistribusi</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Pesanan PO</p>
            <p class="sh-kpi-value">{{ $qty($demand->sum('qty')) }}</p>
            <p class="sh-kpi-note">porsi dari {{ $demand->sum('po_count') }} baris PO di rentang ini</p>
        </div>
    </div>

    <x-filament::section heading="Stok siap per menu">
        <x-slot name="description">
            Rencana Masak disusun gudang sebelum PO masuk. Admin mengambil porsinya lewat centang "Ambil dari stok siap" di PO
            bertanggal kirim sama; varian harga yang berbagi resep mengambil dari masakan yang sama.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Menu (resep)</th>
                        <th class="text-right">Disiapkan</th>
                        <th class="text-right">Terdistribusi</th>
                        <th class="text-right">Sisa</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stock as $row)
                        <tr wire:key="stok-{{ $row['date'] }}-{{ $row['recipe_id'] }}">
                            <td>{{ $tgl($row['date']) }}</td>
                            <td class="font-medium">{{ $row['recipe'] }}</td>
                            <td class="text-right">{{ $qty($row['planned']) }}</td>
                            <td class="text-right">{{ $qty($row['distributed']) }}</td>
                            <td @class(['text-right font-semibold', 'text-danger-600' => $row['remaining'] < 0, 'text-warning-600' => $row['remaining'] > 0])>{{ $qty($row['remaining']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">Belum ada Rencana Masak di rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Tutup stok siap">
        <x-slot name="description">
            Setelah hari produksi selesai dan SPK Rencana Masak-nya ditutup (pemakaian bahan tercatat), tutup stok siap
            tanggal itu: porsi yang belum terdistribusi ke PO menjadi Barang Sisa, dinilai HPP resep.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Rencana Masak</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($days as $day)
                        <tr wire:key="tutup-{{ $day['date'] }}">
                            <td>{{ $tgl($day['date']) }}</td>
                            <td>
                                @foreach ($day['plans'] as $plan)
                                    <a class="text-primary-600 hover:underline" href="{{ \App\Filament\Resources\ProductionOrderResource::getUrl('edit', ['record' => $plan]) }}">{{ $plan->number }}</a>
                                    <span class="text-xs text-gray-500">({{ $plan->statusLabel() }})</span>@if (! $loop->last), @endif
                                @endforeach
                            </td>
                            <td>
                                @if ($day['closing'])
                                    <x-filament::badge color="success">Ditutup</x-filament::badge>
                                    <span class="text-xs text-gray-500">
                                        sisa {{ $qty($day['closing']->leftover_qty) }} porsi
                                        &middot; Rp {{ number_format((float) $day['closing']->leftover_value, 0, ',', '.') }}
                                    </span>
                                @else
                                    <x-filament::badge color="warning">Terbuka</x-filament::badge>
                                @endif
                            </td>
                            <td class="text-right">
                                @unless ($day['closing'])
                                    {{ ($this->closeDayAction)(['date' => $day['date']]) }}
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-500">Belum ada Rencana Masak di rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Rencana belanja">
        <x-slot name="description">
            Dari Form Kebutuhan tiap Rencana Masak (Beli = Kebutuhan − Stok Awal). Susun dan ajukan Form Kebutuhan di halaman
            rencananya; angka di bawah ikut berubah.
        </x-slot>

        <div class="mb-4 flex flex-wrap gap-2 text-sm">
            @forelse ($shopping['plans'] as $plan)
                <a href="{{ \App\Filament\Resources\ProductionOrderResource::getUrl('kebutuhan', ['record' => $plan]) }}"
                   class="rounded-lg border border-gray-200 px-3 py-1.5 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                    <span class="font-semibold">{{ $plan->number }}</span>
                    &middot; {{ $tgl($plan->production_date) }}
                    &middot; {{ $plan->requisition ? ($requisitionLabels[$plan->requisition->status] ?? $plan->requisition->status) : 'Form belum disusun' }}
                </a>
            @empty
                <span class="text-gray-500">Belum ada Rencana Masak di rentang ini.</span>
            @endforelse
        </div>

        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr>
                        <th>Bahan</th>
                        <th class="text-right">Kebutuhan</th>
                        <th class="text-right">Beli</th>
                        <th>Satuan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shopping['items'] as $item)
                        <tr wire:key="belanja-{{ md5($item['item'].$item['unit']) }}">
                            <td class="font-medium">{{ $item['item'] }}</td>
                            <td class="text-right">{{ $qty($item['required']) }}</td>
                            <td class="text-right font-semibold">{{ $qty($item['purchase']) }}</td>
                            <td>{{ $item['unit'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-500">Belum ada Form Kebutuhan yang disusun dari Rencana Masak.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Pesanan PO yang sudah masuk">
        <x-slot name="description">Semua PO di rentang ini (selain yang dibatalkan), per tanggal kirim dan menu.</x-slot>

        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr>
                        <th>Tanggal kirim</th>
                        <th>Menu</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Dari stok siap</th>
                        <th class="text-right">PO</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($demand as $row)
                        <tr wire:key="po-{{ $row['date'] }}-{{ md5($row['product']) }}">
                            <td>{{ $tgl($row['date']) }}</td>
                            <td class="font-medium">{{ $row['product'] }}</td>
                            <td class="text-right">{{ $qty($row['qty']) }}</td>
                            <td class="text-right">{{ $row['from_ready_stock'] > 0 ? $qty($row['from_ready_stock']) : '-' }}</td>
                            <td class="text-right">{{ $row['po_count'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">Belum ada PO di rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
