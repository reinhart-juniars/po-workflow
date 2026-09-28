@php
    $order = $this->getOrder();
    $sheet = $this->getSheet();
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $order->title ?: $order->number }} · {{ $qty($sheet['total_qty']) }} porsi</x-slot>
        <x-slot name="description">
            Komponen diambil dari sub-menu dan kelompok pada resep; bahan mentah tanpa kelompok tidak ditampilkan.
        </x-slot>

        @if ($this->tampilan === 'kartu')
            {{-- Komponen per menu: kartu dua kolom, slot bernomor (minimal 8) untuk catatan tangan. --}}
            <div class="sh-plate-grid">
                @forelse ($sheet['rows'] as $row)
                    <div class="sh-plate-card">
                        <div class="sh-plate-head">
                            <span class="sh-plate-qty">{{ $qty($row['qty']) }}</span>
                            <span>
                                <span class="sh-plate-name">{{ $row['name'] }}</span>
                                <span class="sh-plate-meta">{{ $row['unit'] ?: 'porsi' }}{{ $row['remark'] ? ' · '.$row['remark'] : '' }}</span>
                            </span>
                        </div>
                        <ol class="sh-plate-slots">
                            @for ($slot = 0; $slot < max(count($row['components']), \App\Services\PlatingService::MIN_SLOTS); $slot++)
                                <li><span>{{ $slot + 1 }}</span>{{ $row['components'][$slot] ?? '' }}</li>
                            @endfor
                        </ol>
                    </div>
                @empty
                    <p class="sh-bd-note">SPK ini belum punya baris menu.</p>
                @endforelse
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                        <th class="py-2 pr-4 font-medium">Menu</th>
                        <th class="py-2 pr-4 text-right font-medium">Jumlah</th>
                        <th class="py-2 pr-4 font-medium">Komponen di piring</th>
                        <th class="py-2 font-medium">Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sheet['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4 font-medium">{{ $row['name'] }}</td>
                            <td class="py-2 pr-4 text-right whitespace-nowrap">{{ $qty($row['qty']) }} {{ $row['unit'] }}</td>
                            <td class="py-2 pr-4">
                                @forelse ($row['components'] as $komponen)
                                    <x-filament::badge color="gray" class="mr-1">{{ $komponen }}</x-filament::badge>
                                @empty
                                    <span class="text-gray-400">—</span>
                                @endforelse
                            </td>
                            <td class="py-2 text-gray-500">{{ $row['remark'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-500">SPK ini belum punya baris menu.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
