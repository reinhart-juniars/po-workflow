@php
    $s = $this->getSummary();
    $angka = fn ($v) => number_format((float) $v, 0, ',', '.');
    $persen = $s['porsi_total'] > 0 ? round($s['porsi_tercakup'] / $s['porsi_total'] * 100) : 0;
@endphp

<x-filament-panels::page>
    <div class="sh-kpi" style="--cols: 4">
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Belum dicocokkan</p>
            <p class="sh-kpi-value {{ $s['produk_belum'] > 0 ? 'is-bad' : 'is-ok' }}">{{ $angka($s['produk_belum']) }}</p>
            <p class="sh-kpi-note">produk aktif yang masih perlu keputusan</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Sudah tertaut ke resep</p>
            <p class="sh-kpi-value">{{ $angka($s['produk_tertaut']) }}</p>
            <p class="sh-kpi-note">ikut SPK Produksi &amp; HPP resep</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Tanpa resep</p>
            <p class="sh-kpi-value">{{ $angka($s['produk_tanpa']) }}</p>
            <p class="sh-kpi-note">memang tidak dimasak</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Porsi {{ static::HARI_PENJUALAN }} hari tercakup</p>
            <p class="sh-kpi-value {{ $persen >= 90 ? 'is-ok' : '' }}">{{ $persen }}%</p>
            <p class="sh-kpi-note">{{ $angka($s['porsi_tercakup']) }} dari {{ $angka($s['porsi_total']) }} porsi terjual</p>
        </div>
    </div>

    <x-filament::section
        heading="Cara kerja"
        collapsible
        collapsed
    >
        <div class="sh-kpi-lines">
            <p>Master produk (Admin App) adalah apa yang <strong>dijual</strong>, satu baris per varian harga. Resep (Inventory) adalah cara <strong>membuat</strong>. Keduanya tidak diganti -- yang dikerjakan di sini hanya menautkan.</p>
            <ul class="mt-2 list-disc pl-5">
                <li><strong>Tautkan</strong> bila resepnya sudah ada. Varian harga (10K/12K/15K) yang isinya sama boleh menunjuk resep yang sama; bila porsinya beda, buat resep terpisah.</li>
                <li><strong>Tanpa Resep</strong> untuk produk yang memang tidak dimasak (EXTRA, HARGA UP, barang beli jadi). Produk keluar dari daftar kerja.</li>
                <li><strong>Buat Resep</strong> bila menu ini belum pernah ditulis resepnya (mis. paket rakitan).</li>
            </ul>
            <p class="mt-2">Urutan bawaan mengikuti porsi terjual {{ static::HARI_PENJUALAN }} hari terakhir: yang paling laku diputuskan lebih dulu.</p>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
