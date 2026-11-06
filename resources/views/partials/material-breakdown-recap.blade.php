{{--
  Tabel rekap bahan & stok dari partials.material-breakdown (mewarisi $breakdown,
  $qty, $rp, $standalone).
--}}
    <h3 class="sh-bd-title">Rekap bahan &amp; stok</h3>
    <p class="sh-bd-note">
        Perlu Beli = Kebutuhan − Stok Sistem (saldo Kartu Stok hari ini). Stok belum dikurangi pesanan lain yang belum diproduksi{{ $standalone ? '.' : ', dan Stok Awal fisik tetap diisi dapur di Form Kebutuhan.' }}
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
                    <tr><td colspan="7" class="sh-grid-muted" style="padding: 16px 0">Belum ada bahan yang bisa dihitung — {{ $standalone ? 'resep yang dipilih belum punya bahan.' : 'menu di pesanan ini belum punya resep.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
