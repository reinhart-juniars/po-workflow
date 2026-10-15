@php
    $data = $this->cached();
    $qty = fn ($v) => $v === null ? '-' : rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $rupiah = fn ($v) => $v === null ? '-' : 'Rp '.rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $categories = \App\Models\InventoryItem::categoryOptions();
    $statusLabels = ['mirip' => 'Mirip — pilih dulu', 'baru' => 'Baru', 'sama' => 'Sama', 'semua' => 'Semua'];

    if ($data) {
        $preview = collect($data['preview']);
        $counts = $preview->countBy('status');
        $filtered = $this->filteredRows();
        $pages = max(1, (int) ceil(count($filtered) / $this::PER_PAGE));
        $pageRows = array_slice($filtered, ($this->previewPage - 1) * $this::PER_PAGE, $this::PER_PAGE, true);
        // Ringkasan pilihan saat ini, dihitung dari state (bukan bawaan).
        $chosen = collect($this->choices)->map(fn ($c) => str_starts_with((string) $c, 'link:') || str_starts_with((string) $c, 'price:') ? 'link' : $c)->countBy();
    }
@endphp

<x-filament-panels::page>
    <x-filament::section heading="1. Pilih berkas">
        <x-slot name="description">
            Laporan stok harian gudang seperti biasa (.xlsx): tiap sheet punya kepala kolom NAMA BARANG, SATUAN, HARGA, SISA STOK.
            Jenis item diambil dari nama sheet (BAHAN BAKU, KEMASAN, PERALATAN, INVENTARIS). Sheet lain dilewati.
        </x-slot>

        <form wire:submit="readFile" class="flex flex-wrap items-center gap-3">
            <input type="file" wire:model="upload" accept=".xlsx,.xls" class="text-sm">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="upload,readFile">Baca Berkas</x-filament::button>
            <span wire:loading wire:target="upload" class="text-sm text-gray-500">Mengunggah…</span>
            <span wire:loading wire:target="readFile" class="text-sm text-gray-500">Membaca dan mencocokkan… (bisa ±30 detik)</span>
        </form>
        @error('upload')
            <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
        @enderror
    </x-filament::section>

    @if ($data)
        <x-filament::section heading="2. Ringkasan & stok">
            <x-slot name="description">{{ $this->fileName }} · {{ $preview->count() }} barang dari {{ count($data['sheets']) }} sheet</x-slot>

            <div class="sh-kpi" style="--cols: 4">
                <div class="sh-kpi-cell">
                    <p class="sh-kpi-label">Sama</p>
                    <p class="sh-kpi-value">{{ $counts['sama'] ?? 0 }}</p>
                    <p class="sh-kpi-note">nama persis ada; bawaan: tautkan, item tidak diubah</p>
                </div>
                <div class="sh-kpi-cell">
                    <p class="sh-kpi-label">Mirip</p>
                    <p @class(['sh-kpi-value', 'is-bad' => ($counts['mirip'] ?? 0) > 0])>{{ $counts['mirip'] ?? 0 }}</p>
                    <p class="sh-kpi-note">ada item bernama mirip; bawaan: lewati sampai dipilih</p>
                </div>
                <div class="sh-kpi-cell">
                    <p class="sh-kpi-label">Baru</p>
                    <p class="sh-kpi-value">{{ $counts['baru'] ?? 0 }}</p>
                    <p class="sh-kpi-note">bawaan: buat item baru</p>
                </div>
                <div class="sh-kpi-cell">
                    <p class="sh-kpi-label">Pilihan saat ini</p>
                    <p class="sh-kpi-value is-ok">{{ $chosen['new'] ?? 0 }}</p>
                    <p class="sh-kpi-note">dibuat · {{ $chosen['link'] ?? 0 }} ditautkan · {{ $chosen['skip'] ?? 0 }} dilewati</p>
                </div>
            </div>

            <div class="mt-4 space-y-1 text-sm">
                <p>
                    Sheet dibaca:
                    @foreach ($data['sheets'] as $sheet => $count)
                        <span class="font-medium">{{ $sheet }}</span> ({{ $count }} baris, {{ $categories[\App\Imports\StockReportReader::categoryForSheet($sheet)] ?? '-' }})@if (! $loop->last), @endif
                    @endforeach
                </p>
                @if ($data['skipped'])
                    <p class="text-gray-500">
                        Dilewati:
                        @foreach ($data['skipped'] as $sheet => $reason)
                            {{ $sheet }} ({{ $reason }})@if (! $loop->last), @endif
                        @endforeach
                    </p>
                @endif
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-4 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                <label class="inline-flex items-center gap-2 font-medium">
                    <input type="checkbox" wire:model.live="withStock" class="h-4 w-4 rounded">
                    Isi Saldo Awal Kartu Stok dari kolom Sisa Stok
                </label>
                @if ($this->withStock)
                    <label class="inline-flex items-center gap-2">
                        Tanggal saldo awal
                        <input type="date" wire:model="stockDate" max="{{ today()->toDateString() }}" class="fi-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    </label>
                    <span class="text-gray-500">Hanya untuk item yang belum punya Kartu Stok dan satuannya sama dengan laporan. Item yang sudah berjalan dikoreksi lewat Opname Bahan.</span>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="3. Periksa dan pilih">
            <x-slot name="description">
                Laporan gudang dan sistem sering beda ejaan dan satuan (laporan per Kg, resep per gram). Untuk barang Mirip, pilih item yang benar
                atau "Buat item baru". "Perbarui harga" hanya ditawarkan bila satuannya sama, supaya HPP resep tidak rusak.
            </x-slot>

            <div class="mb-3 flex flex-wrap items-center gap-2">
                @foreach ($statusLabels as $key => $label)
                    <x-filament::button size="sm" :color="$this->statusFilter === $key ? 'primary' : 'gray'" wire:click="$set('statusFilter', '{{ $key }}')">
                        {{ $label }} ({{ $key === 'semua' ? $preview->count() : ($counts[$key] ?? 0) }})
                    </x-filament::button>
                @endforeach
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Cari nama barang…" class="fi-input ml-auto w-56 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
            </div>
            <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                <span class="text-gray-500">{{ count($filtered) }} barang di tampilan ini:</span>
                <x-filament::button size="xs" color="gray" wire:click="setAll('new')">Semua jadi item baru</x-filament::button>
                <x-filament::button size="xs" color="gray" wire:click="setAll('skip')">Semua dilewati</x-filament::button>
            </div>

            <div class="overflow-x-auto">
                <table class="sh-table">
                    <thead>
                        <tr>
                            <th>Barang di laporan</th>
                            <th>Satuan</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Sisa stok</th>
                            <th>Status</th>
                            <th>Pilihan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pageRows as $index => $row)
                            @php
                                $choice = (string) ($this->choices[$index] ?? $row['default']);
                                $linked = collect($row['candidates'])->first(fn ($c) => in_array($choice, ['link:'.$c['id'], 'price:'.$c['id']], true));
                            @endphp
                            <tr wire:key="sr-{{ $index }}">
                                <td>
                                    <span class="font-medium">{{ $row['name'] }}</span>
                                    <span class="block text-xs text-gray-500">
                                        {{ $row['sheet'] }} · baris {{ $row['row'] }} · {{ $categories[$row['category']] ?? $row['category'] }}
                                        @if ($row['duplicates'] > 0) · muncul {{ $row['duplicates'] + 1 }}× (diimpor sekali) @endif
                                    </span>
                                </td>
                                <td>{{ $row['unit'] ?? '-' }}</td>
                                <td class="text-right">{{ $rupiah($row['price']) }}</td>
                                <td class="text-right">{{ $qty($row['qty']) }}</td>
                                <td>
                                    @if ($row['status'] === 'sama')
                                        <x-filament::badge color="success">Sama</x-filament::badge>
                                    @elseif ($row['status'] === 'mirip')
                                        <x-filament::badge color="warning">Mirip</x-filament::badge>
                                    @else
                                        <x-filament::badge color="info">Baru</x-filament::badge>
                                    @endif
                                </td>
                                <td class="min-w-80">
                                    <select wire:model.live="choices.{{ $index }}" class="fi-input block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                        <option value="new">Buat item baru ({{ $categories[$row['category']] ?? $row['category'] }})</option>
                                        <option value="skip">Lewati</option>
                                        @if ($row['candidates'])
                                            <optgroup label="Tautkan ke item yang ada">
                                                @foreach ($row['candidates'] as $candidate)
                                                    <option value="link:{{ $candidate['id'] }}">{{ $candidate['name'] }} ({{ $candidate['unit'] }}) — {{ $candidate['score'] }}%</option>
                                                    @if ($candidate['same_unit'] && $row['price'] !== null)
                                                        <option value="price:{{ $candidate['id'] }}">{{ $candidate['name'] }} + perbarui harga {{ $rupiah($candidate['price']) }} → {{ $rupiah($row['price']) }}</option>
                                                    @endif
                                                @endforeach
                                            </optgroup>
                                        @endif
                                    </select>
                                    @if ($this->withStock && $row['qty'] && $choice !== 'skip')
                                        <p class="mt-1 text-xs text-gray-500">
                                            @if ($choice === 'new')
                                                Saldo awal {{ $qty($row['qty']) }} {{ $row['unit'] }}
                                            @elseif ($linked && ! $linked['same_unit'])
                                                Stok tidak diisi: satuan item {{ $linked['unit'] }} ≠ laporan {{ $row['unit'] ?? '-' }}
                                            @elseif ($linked && $linked['has_stock'])
                                                Stok tidak diisi: item sudah punya Kartu Stok
                                            @elseif ($linked)
                                                Saldo awal {{ $qty($row['qty']) }} {{ $row['unit'] }}
                                            @endif
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-gray-500">Tidak ada barang di tampilan ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($pages > 1)
                <div class="mt-3 flex items-center justify-center gap-2 text-sm">
                    <x-filament::button size="xs" color="gray" :disabled="$this->previewPage <= 1" wire:click="$set('previewPage', {{ max(1, $this->previewPage - 1) }})">‹ Sebelumnya</x-filament::button>
                    <span>Halaman {{ $this->previewPage }} dari {{ $pages }}</span>
                    <x-filament::button size="xs" color="gray" :disabled="$this->previewPage >= $pages" wire:click="$set('previewPage', {{ min($pages, $this->previewPage + 1) }})">Berikutnya ›</x-filament::button>
                </div>
            @endif

            <div class="mt-4 flex justify-end gap-2">
                <x-filament::button color="gray" wire:click="resetPreview">Batal</x-filament::button>
                <x-filament::button wire:click="save" wire:loading.attr="disabled" wire:target="save" icon="heroicon-m-check"
                    wire:confirm="Simpan {{ $chosen['new'] ?? 0 }} item baru dan {{ $chosen['link'] ?? 0 }} tautan{{ $this->withStock ? ' beserta saldo awal stok' : '' }}?">
                    Simpan Impor
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
