@php
    $s = $this->getSummary();
    $angka = fn ($v) => number_format((float) $v, 0, ',', '.');
@endphp

<x-filament-panels::page>
    <div class="sh-kpi" style="--cols: 4">
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Menu aktif</p>
            <p class="sh-kpi-value">{{ $angka($s['menus']) }}</p>
            <p class="sh-kpi-note">resep Menu Utama &amp; Sub-Menu</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Sudah punya template</p>
            <p class="sh-kpi-value">{{ $angka($s['with_template']) }}</p>
            <p class="sh-kpi-note">disalin ke Lembar Kerja SPK Produksi</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Pekerjaan</p>
            <p class="sh-kpi-value">{{ $angka($s['tasks']) }}</p>
            <p class="sh-kpi-note">baris template</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Pelaksana aktif</p>
            <p class="sh-kpi-value">{{ $angka($s['workers']) }}</p>
            <p class="sh-kpi-note">pilihan PIC di Lembar Kerja</p>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
