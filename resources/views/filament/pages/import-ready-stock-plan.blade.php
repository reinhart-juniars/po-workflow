@php
    $products = $this->preview ? $this->getProductOptions() : [];
    $hasRecipe = $this->preview ? $this->getRecipeFlags() : [];
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $rupiah = fn ($v) => $v === null ? '-' : number_format((float) $v, 0, ',', '.');
    $tgl = fn ($v) => \Illuminate\Support\Carbon::parse($v)->translatedFormat('l, d M Y');
@endphp

<x-filament-panels::page>
    <x-filament::section heading="1. Pilih berkas">
        <x-slot name="description">
            Excel rencana masak mingguan seperti biasa: tiap hari diawali sel tanggal (kuning) dengan nama hari di kanannya,
            lalu menu | harga | qty, dan baris total (SUM). Menu di atas total menjadi Rencana Masak (stok siap);
            pesanan khusus di bawah total tidak diimpor karena itu PO customer.
        </x-slot>

        <form wire:submit="readFile" class="flex flex-wrap items-center gap-3">
            <input type="file" wire:model="upload" accept=".xlsx,.xls" class="text-sm">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="upload,readFile">Baca Berkas</x-filament::button>
            <span wire:loading wire:target="upload" class="text-sm text-gray-500">Mengunggah…</span>
        </form>
        @error('upload')
            <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
        @enderror
    </x-filament::section>

    @if ($this->preview)
        <x-filament::section heading="2. Periksa pencocokan menu">
            <x-slot name="description">
                {{ $this->fileName }} · {{ count($this->preview) }} hari.
                Menu yang belum cocok pilihkan produknya di Master Menu — pilihan diingat untuk impor minggu berikutnya.
                Hanya menu berresep yang bisa didistribusikan dari stok siap; yang lain tetap masuk rencana sebagai catatan dapur.
            </x-slot>

            @foreach ($this->preview as $day)
                <div class="mb-8" wire:key="hari-{{ $day['date'] }}">
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <h3 class="text-base font-semibold">{{ $tgl($day['date']) }}</h3>
                        <span class="text-sm text-gray-500">{{ count($day['items']) }} menu · {{ $qty(array_sum(array_column($day['items'], 'qty'))) }} porsi</span>
                        @if (! $day['total_ok'])
                            <x-filament::badge color="danger">Total Excel {{ $qty($day['sheet_total']) }} tidak sama dengan jumlah baris — periksa berkas</x-filament::badge>
                        @endif
                        @if ($day['plan'])
                            <x-filament::badge :color="$day['plan']['editable'] ? 'info' : 'gray'">
                                {{ $day['plan']['editable'] ? 'Memperbarui '.$day['plan']['number'] : $day['plan']['number'].' sudah '.mb_strtolower($day['plan']['status']).' — dilewati' }}
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="success">Rencana Masak baru</x-filament::badge>
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="sh-table">
                            <thead>
                                <tr>
                                    <th>Menu di Excel</th>
                                    <th class="text-right">Harga</th>
                                    <th class="text-right">Qty</th>
                                    <th>Master Menu</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($day['items'] as $item)
                                    @php
                                        $chosen = $this->choices[$item['key']] ?? null;
                                        $candidateIds = array_column($item['candidates'], 'id');
                                    @endphp
                                    <tr wire:key="baris-{{ $item['key'] }}">
                                        <td class="font-medium">{{ $item['name'] }}</td>
                                        <td class="text-right">{{ $rupiah($item['price']) }}</td>
                                        <td class="text-right">{{ $qty($item['qty']) }}</td>
                                        <td class="min-w-72">
                                            <select wire:model.live="choices.{{ $item['key'] }}" class="fi-input block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                                <option value="">— belum dicocokkan —</option>
                                                @if ($item['candidates'])
                                                    <optgroup label="Saran">
                                                        @foreach ($item['candidates'] as $candidate)
                                                            <option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}{{ $candidate['has_recipe'] ? '' : ' (tanpa resep)' }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                                <optgroup label="Semua menu aktif">
                                                    @foreach ($products as $id => $name)
                                                        @continue(in_array($id, $candidateIds, true))
                                                        <option value="{{ $id }}">{{ $name }}</option>
                                                    @endforeach
                                                </optgroup>
                                            </select>
                                        </td>
                                        <td>
                                            @if (! $chosen)
                                                <x-filament::badge color="warning">Pilih menu</x-filament::badge>
                                            @elseif (! ($hasRecipe[$chosen] ?? false))
                                                <x-filament::badge color="gray">Tanpa resep</x-filament::badge>
                                            @elseif ((int) $chosen !== (int) $item['product_id'])
                                                <x-filament::badge color="info">Dipilih manual</x-filament::badge>
                                            @else
                                                <x-filament::badge color="success">{{ $item['via'] === 'alias' ? 'Cocok (diingat)' : 'Cocok' }}</x-filament::badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($day['specials'])
                        <div class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                            <p class="font-semibold">Pesanan khusus (tidak diimpor — pastikan PO-nya sudah dibuat):</p>
                            <ul class="ml-4 list-disc">
                                @foreach ($day['specials'] as $special)
                                    <li>{{ $special['name'] }}@if ($special['qty'] !== null) — {{ $qty($special['qty']) }} × Rp {{ $rupiah($special['price']) }}@endif</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endforeach

            <div class="flex justify-end">
                <x-filament::button wire:click="save" wire:loading.attr="disabled" icon="heroicon-m-check">Simpan Rencana Masak</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
