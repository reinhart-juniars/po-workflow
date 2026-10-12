{{--
  Satu tingkat pohon resep (RecipeCostService::tree): bahan tampil sebagai baris,
  sub-menu sebagai <details> berpanah yang membuka rinciannya sendiri. Dipanggil
  rekursif dengan $depth + 1. Tanpa Alpine (cangkang Blade tidak memuatnya).

  Variabel: $nodes, $depth (0 = baris langsung milik menu), $qty, $rp (formatter).
--}}
@foreach ($nodes as $node)
    @php
        $cost = $node['cost'] === null ? '–' : $rp($node['cost']);
        $flag = ! $node['complete'] ? ($node['issue'] ?? 'Ada rincian yang belum lengkap.') : null;
    @endphp

    @if ($node['kind'] === 'recipe' && $node['children'] !== [])
        <details class="sh-tree-node" style="--depth: {{ $depth }}">
            <summary class="sh-tree-row">
                <span class="sh-tree-name">
                    <svg class="sh-tree-caret" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                    <span class="sh-tree-label" title="{{ $node['name'] }}">{{ $node['name'] }}</span>
                    <span class="sh-tree-tag">Sub-menu · {{ count($node['children']) }}</span>
                    @if ($flag)
                        <span class="sh-tree-flag" title="{{ $flag }}">belum lengkap</span>
                    @endif
                </span>
                <span class="num" data-unit="{{ $node['unit'] }}">{{ $qty($node['qty']) }}</span>
                <span class="sh-grid-muted">{{ $node['unit'] }}</span>
                <span class="num">{{ $cost }}</span>
            </summary>
            @include('partials.recipe-tree', ['nodes' => $node['children'], 'depth' => $depth + 1])
        </details>
    @else
        <div class="sh-tree-row is-leaf" style="--depth: {{ $depth }}">
            <span class="sh-tree-name">
                <span class="sh-tree-label" title="{{ $node['name'] }}">{{ $node['name'] }}</span>
                @if ($node['kind'] === 'recipe')
                    <span class="sh-tree-tag">Sub-menu</span>
                @endif
                @if ($flag)
                    <span class="sh-tree-flag" title="{{ $flag }}">{{ $node['kind'] === 'unmatched' ? 'belum tertaut' : 'belum lengkap' }}</span>
                @endif
            </span>
            <span class="num" data-unit="{{ $node['unit'] }}">{{ $qty($node['qty']) }}</span>
            <span class="sh-grid-muted">{{ $node['unit'] }}</span>
            <span class="num">{{ $cost }}</span>
        </div>
    @endif
@endforeach
