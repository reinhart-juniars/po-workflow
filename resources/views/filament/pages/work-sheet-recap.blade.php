@php
    $recap = $this->recap();
    $tanggal = $recap['date']->copy()->locale('id');
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="rekap-tanggal" class="text-sm font-medium">Tanggal kerja</label>
                <div class="mt-1 flex items-center gap-2">
                    <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" wire:click="shiftDay(-1)" label="Hari sebelumnya" />
                    <x-filament::input.wrapper>
                        <x-filament::input id="rekap-tanggal" type="date" wire:model.live="tanggal" />
                    </x-filament::input.wrapper>
                    <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" wire:click="shiftDay(1)" label="Hari berikutnya" />
                </div>
            </div>
            <div>
                <label for="rekap-urut" class="text-sm font-medium">Urutkan</label>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select id="rekap-urut" wire:model.live="urut">
                        <option value="menu">Per menu</option>
                        <option value="pelaksana">Per pelaksana</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <p class="text-sm text-gray-500">
                {{ $tanggal->translatedFormat('l, d F Y') }} — pekerjaan dari semua SPK Produksi yang dijadwalkan dikerjakan hari ini
                (H-1 dari SPK besok, H-2 / H-3 dari SPK sesudahnya). Baris dengan pelaksana, pekerjaan, dan bahan yang sama digabung.
            </p>
        </div>

        @if ($recap['orders'] !== [])
            <p class="mt-3 text-sm">
                <span class="font-medium">Sumber:</span>
                @foreach ($recap['orders'] as $order)
                    {{ $order['number'] }} (SPK {{ $order['date'] }}@if ($order['time']) {{ $order['time'] }}@endif, {{ $order['days'] === 0 ? 'H' : 'H-'.$order['days'] }})@if (! $loop->last), @endif
                @endforeach
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Pekerjaan ({{ count($recap['rows']) }})">
        <div class="overflow-x-auto">
            <table class="sh-table">
                <thead>
                    <tr class="border-b border-gray-200 text-left">
                        {{-- .sh-table melebarkan kolom pertama 100%; di sini kolom No dibuat sempit dan kolom teks boleh turun baris. --}}
                        <th class="py-2 pr-3 font-medium" style="width: 1%">No</th>
                        <th class="py-2 pr-3 font-medium">Pelaksana</th>
                        <th class="py-2 pr-3 font-medium">Menu</th>
                        <th class="py-2 pr-3 font-medium">Pekerjaan</th>
                        <th class="py-2 pr-3 font-medium">Bahan / Objek</th>
                        <th class="py-2 pr-3 font-medium">Jumlah</th>
                        <th class="py-2 pr-3 font-medium">Selesai</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recap['rows'] as $i => $row)
                        <tr class="border-b border-gray-100">
                            <td class="py-2 pr-3" style="width: 1%">{{ $i + 1 }}</td>
                            <td class="py-2 pr-3 font-medium">{{ $row['worker'] ?: '—' }}</td>
                            <td class="sh-wrap py-2 pr-3">
                                {{ implode(' + ', $row['menus']) ?: '—' }}
                                @if ($row['count'] > 1)
                                    <div class="text-xs text-gray-500">{{ $row['count'] }} baris digabung · {{ implode(', ', $row['orders']) }}</div>
                                @endif
                            </td>
                            <td class="sh-wrap py-2 pr-3">{{ $row['task'] }}</td>
                            <td class="sh-wrap py-2 pr-3">{{ $row['object'] }}</td>
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $row['quantity'] }}</td>
                            <td class="py-2 pr-3">{{ $row['done'] ? '✓' : '' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-gray-500">
                                Belum ada pekerjaan untuk tanggal ini. Pekerjaan diisi di Lembar Kerja tiap SPK Produksi;
                                kolom "Dikerjakan" menentukan harinya.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Tugas umum">
        @if ($recap['chores']->isEmpty())
            <p class="text-sm text-gray-500">Belum ada tugas umum. Tambahkan lewat tombol "Tugas Umum" di atas.</p>
        @else
            <table class="sh-table">
                <tbody>
                    @foreach ($recap['chores'] as $chore)
                        <tr class="border-b border-gray-100">
                            <td class="py-2 pr-3 font-medium" style="width: 180px">{{ $chore->worker_name ?: '—' }}</td>
                            <td class="sh-wrap py-2 pr-3">{{ $chore->task }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
