{{--
  Breakdown menu -> bahan mentah, dicocokkan dengan stok (MaterialBreakdownService).
  Dipakai di Admin › Detail PO dan Inventory › SPK Produksi › Kebutuhan Bahan, jadi
  hanya memakai kelas dari resources/css/shell.css (CSS Filament tidak memuat
  utilitas responsif Tailwind aplikasi).

  Variabel: $breakdown (hasil service), $matchUrl (Pencocokan Menu, boleh null).
--}}
@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0';
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $s = $breakdown['summary'];
@endphp

<div class="sh-bd">
    <div class="sh-kpi" style="--cols: 4">
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Porsi terhitung lengkap</p>
            <p class="sh-kpi-value {{ ($s['coverage_pct'] ?? 0) < 100 ? 'is-bad' : 'is-ok' }}">{{ $s['coverage_pct'] === null ? '–' : $qty($s['coverage_pct']).'%' }}</p>
            <p class="sh-kpi-note">{{ $s['menu_with_recipe'] }} dari {{ $s['menu_count'] }} menu punya resep</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Bahan dibutuhkan</p>
            <p class="sh-kpi-value">{{ $s['ingredient_count'] }}</p>
            <p class="sh-kpi-note">Estimasi {{ $rp($s['total_cost']) }}</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Perlu dibeli</p>
            <p class="sh-kpi-value {{ $s['to_buy_count'] > 0 ? 'is-bad' : 'is-ok' }}">{{ $s['to_buy_count'] }} bahan</p>
            <p class="sh-kpi-note">Estimasi {{ $rp($s['to_buy_cost']) }}</p>
        </div>
        <div class="sh-kpi-cell">
            <p class="sh-kpi-label">Stok belum tercatat</p>
            <p class="sh-kpi-value">{{ $s['unknown_stock_count'] }} bahan</p>
            <p class="sh-kpi-note">Dihitung perlu beli semua</p>
        </div>
    </div>

    @if ($breakdown['missing'] !== [] || $breakdown['issues'] !== [])
        <div class="sh-bd-alert">
            @if ($breakdown['missing'] !== [])
                <p><b>{{ count($breakdown['missing']) }} menu belum punya resep</b> — bahannya tidak ikut terhitung:
                    {{ collect($breakdown['missing'])->map(fn ($m) => $m['label'].' ('.$qty($m['qty']).' '.$m['unit'].')')->implode(', ') }}.
                    @if ($matchUrl)
                        <a href="{{ $matchUrl }}">Tautkan resepnya di Pencocokan Menu</a>.
                    @endif
                </p>
            @endif
            @if ($breakdown['issues'] !== [])
                <details>
                    <summary>{{ count($breakdown['issues']) }} catatan hitungan resep (bahan belum tertaut, satuan belum bisa dikonversi, dll.)</summary>
                    <ul>
                        @foreach ($breakdown['issues'] as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    @endif

    <h3 class="sh-bd-title">Rekap bahan &amp; stok</h3>
    <p class="sh-bd-note">
        Perlu Beli = Kebutuhan − Stok Sistem (saldo Kartu Stok hari ini). Stok belum dikurangi pesanan lain yang belum diproduksi,
        dan Stok Awal fisik tetap diisi dapur di Form Kebutuhan.
    </p>
    <div class="sh-grid-wrap">
        <table class="sh-grid sh-bd-recap">
            <colgroup>
                <col>
                <col style="width: 110px">
                <col style="width: 110px">
                <col style="width: 110px">
                <col style="width: 70px">
                <col style="width: 120px">
                <col style="width: 30%">
            </colgroup>
            <thead>
                <tr>
                    <th>Bahan</th>
                    <th class="num">Kebutuhan</th>
                    <th class="num">Stok Sistem</th>
                    <th class="num">Perlu Beli</th>
                    <th>Satuan</th>
                    <th class="num">Est. Biaya Beli</th>
                    <th>Dipakai oleh</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($breakdown['recap'] as $row)
                    <tr>
                        <td><div class="sh-grid-name" title="{{ $row['name'] }}">{{ $row['name'] }}</div></td>
                        <td class="num">{{ $qty($row['qty']) }}</td>
                        <td class="num">
                            @if ($row['stock'] === null)
                                <span class="sh-grid-muted" title="Belum ada gerakan di Kartu Stok">belum tercatat</span>
                            @else
                                {{ $qty($row['stock']) }}
                            @endif
                        </td>
                        <td class="num {{ $row['to_buy'] > 0 ? 'sh-grid-bad' : 'sh-grid-muted' }}">{{ $row['to_buy'] > 0 ? $qty($row['to_buy']) : 'cukup' }}</td>
                        <td class="sh-grid-muted">{{ $row['unit'] }}</td>
                        <td class="num">{{ $row['to_buy'] > 0 ? $rp($row['to_buy_cost']) : '–' }}</td>
                        <td><div class="sh-grid-name sh-grid-muted" title="{{ implode(', ', $row['menus']) }}">{{ implode(', ', $row['menus']) }}</div></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="sh-grid-muted" style="padding: 16px 0">Belum ada bahan yang bisa dihitung — menu di pesanan ini belum punya resep.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($breakdown['menus'] !== [])
        <h3 class="sh-bd-title">Rincian per menu</h3>
        <div class="sh-bd-menus">
            @foreach ($breakdown['menus'] as $menu)
                <details class="sh-bd-menu">
                    <summary>
                        <span class="sh-bd-menu-name">{{ $menu['label'] }}</span>
                        <span class="sh-bd-menu-meta">
                            {{ $qty($menu['qty']) }} {{ $menu['unit'] }} · {{ count($menu['tree']) }} komponen
                            @php $subs = collect($menu['tree'])->where('kind', 'recipe')->count(); @endphp
                            @if ($subs > 0)
                                ({{ $subs }} sub-menu)
                            @endif
                            · {{ count($menu['rows']) }} bahan mentah · {{ $rp($menu['total_cost']) }}
                            @if ($menu['issues'] !== [])
                                · <span class="sh-grid-bad">belum lengkap</span>
                            @endif
                        </span>
                    </summary>
                    @php $subCount = collect($menu['tree'])->where('kind', 'recipe')->count(); @endphp
                    <div class="sh-bd-menu-bar">
                        <p class="sh-bd-note">
                            @if ($menu['recipe'] !== $menu['label'])
                                Resep: {{ $menu['recipe'] }} ·
                            @endif
                            Susunan resep untuk {{ $qty($menu['qty']) }} {{ $menu['unit'] }}; jumlah dalam satuan takaran resep.
                        </p>
                        @if ($subCount > 0)
                            <button type="button" class="sh-tree-toggle" data-tree-toggle>Buka semua sub-menu</button>
                        @endif
                    </div>
                    @if ($menu['tree'] !== [])
                        <div class="sh-tree" role="table" aria-label="Susunan resep {{ $menu['label'] }}">
                            <div class="sh-tree-row sh-tree-head" role="row">
                                <span>Komponen</span><span class="num">Jumlah</span><span>Satuan</span><span class="num">Biaya</span>
                            </div>
                            @include('partials.recipe-tree', ['nodes' => $menu['tree'], 'depth' => 0])
                        </div>
                    @else
                        <p class="sh-bd-note sh-grid-muted">Belum ada bahan yang bisa dihitung.</p>
                    @endif
                    @if ($menu['issues'] !== [])
                        <ul class="sh-bd-issues">
                            @foreach ($menu['issues'] as $issue)
                                <li>{{ $issue }}</li>
                            @endforeach
                        </ul>
                    @endif
                </details>
            @endforeach
        </div>
    @endif
</div>

@once
    {{-- Satu pendengar untuk semua tombol "Buka semua sub-menu" (tanpa Alpine). --}}
    <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-tree-toggle]');
            if (!button) return;
            var nodes = button.closest('.sh-bd-menu').querySelectorAll('.sh-tree-node');
            var open = Array.prototype.some.call(nodes, function (node) { return !node.open; });
            nodes.forEach(function (node) { node.open = open; });
            button.textContent = open ? 'Tutup semua sub-menu' : 'Buka semua sub-menu';
        });
    </script>
@endonce
