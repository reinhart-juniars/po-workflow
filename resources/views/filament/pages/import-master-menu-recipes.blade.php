@php
    $data = $this->cached();
    $preview = $data['preview'] ?? null;
    $angka = fn ($v) => number_format((float) $v, 0, ',', '.');
@endphp

<x-filament-panels::page>
    <x-filament::section heading="1. Unggah app.db">
        <x-slot name="description">
            Ambil berkas <strong>app.db</strong> dari PC yang menjalankan Master Menu Revamp, di folder
            <code>Master Menu Revamp/app/data</code>. Tutup aplikasi Master Menu dulu supaya berkasnya utuh.
            Yang ditambahkan hanya resep yang <strong>belum ada</strong> di 3S ONE, beserta bahan baru yang dipakainya.
            Resep dan bahan yang sudah ada tidak diubah, termasuk bila isinya di Master Menu sudah berbeda.
        </x-slot>

        <form wire:submit="readFile" class="flex flex-wrap items-center gap-3">
            <input type="file" wire:model="upload" accept=".db,.sqlite,.sqlite3" class="text-sm">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="upload,readFile">Baca Berkas</x-filament::button>
            <span wire:loading wire:target="upload" class="text-sm text-gray-500">Mengunggah…</span>
            <span wire:loading wire:target="readFile" class="text-sm text-gray-500">Membaca…</span>
        </form>
        @error('upload')
            <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
        @enderror
    </x-filament::section>

    @if ($preview)
        <x-filament::section heading="2. Pratinjau">
            <x-slot name="description">{{ $this->fileName }} — belum ada yang disimpan.</x-slot>

            <div class="sh-kpi" style="--cols: 4">
                <div class="sh-kpi-cell">
                    <p class="sh-kpi-label">Resep baru</p>
                    <p class="sh-kpi-value">{{ $angka(count($preview['resep_baru'])) }}</p>
                    <p class="sh-kpi-note">akan ditambahkan</p>
                </div>
                <div class="sh-kpi-cell">
                    <p class="sh-kpi-label">Resep sudah ada</p>
                    <p class="sh-kpi-value">{{ $angka($preview['resep_sudah_ada']) }}</p>
                    <p class="sh-kpi-note">tidak diubah</p>
                </div>
                <div class="sh-kpi-cell">
                    <p class="sh-kpi-label">Bahan baru</p>
                    <p class="sh-kpi-value">{{ $angka(count($preview['bahan_baru'])) }}</p>
                    <p class="sh-kpi-note">dipakai resep baru</p>
                </div>
                <div class="sh-kpi-cell">
                    <p class="sh-kpi-label">Baris resep belum tertaut</p>
                    <p class="sh-kpi-value {{ $preview['baris_belum_tertaut'] > 0 ? 'is-bad' : 'is-ok' }}">{{ $angka($preview['baris_belum_tertaut']) }}</p>
                    <p class="sh-kpi-note">dari {{ $angka($preview['baris_resep']) }}; masuk Bahan Belum Cocok</p>
                </div>
            </div>

            @if ($preview['resep_baru'] === [])
                <p class="mt-4 text-sm text-gray-600">Semua resep di berkas ini sudah ada di 3S ONE. Tidak ada yang perlu diimpor.</p>
            @else
                <details class="mt-4" open>
                    <summary class="cursor-pointer text-sm font-medium">Resep baru ({{ count($preview['resep_baru']) }})</summary>
                    <ul class="mt-2 list-disc pl-5 text-sm">
                        @foreach ($preview['resep_baru'] as $name)
                            <li>{{ $name }}</li>
                        @endforeach
                    </ul>
                </details>

                @if ($preview['bahan_baru'] !== [])
                    <details class="mt-3" open>
                        <summary class="cursor-pointer text-sm font-medium">Bahan baru ({{ count($preview['bahan_baru']) }})</summary>
                        <ul class="mt-2 list-disc pl-5 text-sm">
                            @foreach ($preview['bahan_baru'] as $bahan)
                                <li>
                                    {{ $bahan['name'] }} — harga per {{ $bahan['unit'] }}
                                    @if ($bahan['perlu_cek'])
                                        <span class="text-danger-600">(satuan kosong di Master Menu; dicatat "pcs", cek di Item Inventaris)</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                <div class="mt-4 flex flex-wrap gap-3">
                    <x-filament::button wire:click="import" wire:loading.attr="disabled" wire:target="import">
                        Impor {{ count($preview['resep_baru']) }} Resep Baru
                    </x-filament::button>
                    <x-filament::button color="gray" wire:click="cancel">Batal</x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @endif

    @if ($this->result)
        <x-filament::section heading="Selesai">
            <p class="text-sm">
                {{ count($this->result['resep_baru']) }} resep baru dan {{ count($this->result['bahan_baru']) }} bahan baru sudah masuk.
                {{ $this->result['baris_tertaut_keputusan'] }} baris resep langsung tertaut lewat keputusan Bahan Belum Cocok yang sudah ada;
                {{ $this->result['baris_belum_tertaut'] }} baris masih perlu dicocokkan.
                Resep baru sekarang muncul di pilihan <strong>Tautkan</strong> pada Pencocokan Menu.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
