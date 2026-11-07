@php
    $sheet = $this->getSheet();
    $order = $this->getOrder();
    $canEdit = $this->canEditSheet();
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0';
@endphp

<x-filament-panels::page>
    <p class="sh-pra-intro">
        Dikerjakan tanggal <b>{{ $order->workDate()?->format('d/m/Y') ?? '-' }}</b>@if ($order->production_time), jam <b>{{ $order->production_time }}</b>@endif.
        @if ($canEdit)
            Klik nama komponen untuk mengatur tampilannya. Setelan diingat untuk SPK berikutnya.
        @else
            SPK ini tidak bisa diatur lagi, tetapi lembarnya tetap bisa dicetak.
        @endif
    </p>

    <div class="sh-pra">
        @forelse ($sheet['menus'] as $menu)
            <section class="sh-pra-menu">
                <header class="sh-pra-menu-head">
                    <span class="sh-pra-no">{{ $menu['no'] }}</span>
                    <span class="sh-pra-title">{{ $menu['title'] }}</span>
                    <span class="sh-pra-qty">{{ $menu['qty'] === null ? '' : $qty($menu['qty']) }} {{ $menu['unit'] }}</span>
                </header>

                @foreach ($menu['components'] as $component)
                    <div class="sh-pra-component">
                        @php $args = ['recipe' => $component['recipe_id'], 'key' => $component['key']]; @endphp

                        @if ($component['name'] !== null)
                            <div class="sh-pra-row is-head">
                                @if ($canEdit)
                                    <button type="button" class="sh-pra-edit" wire:click="mountAction('atur', {{ \Illuminate\Support\Js::from($args) }})">{{ $component['name'] }}</button>
                                @else
                                    <span class="sh-pra-name">{{ $component['name'] }}</span>
                                @endif
                                @if ($component['custom'])
                                    <span class="sh-pra-tag">khusus SPK ini</span>
                                @endif
                                @if ($component['note'])
                                    <span class="sh-pra-note">{{ $component['note'] }}</span>
                                @endif
                                <span class="sh-pra-num">{{ $component['show_qty'] ? $qty($component['qty']) : '' }}</span>
                                <span class="sh-pra-unit">{{ $component['show_qty'] ? $component['unit'] : '' }}</span>
                            </div>
                        @elseif ($canEdit)
                            <button type="button" class="sh-pra-edit is-quiet" wire:click="mountAction('atur', {{ \Illuminate\Support\Js::from($args) }})">Atur bahan tanpa bagian</button>
                        @endif

                        @foreach ($component['rows'] as $row)
                            <div class="sh-pra-row">
                                <span class="sh-pra-name">{{ $row['name'] }}</span>
                                <span class="sh-pra-num">{{ $qty($row['qty']) }}</span>
                                <span class="sh-pra-unit">{{ $row['unit'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endforeach

                @if ($menu['hidden'] !== [])
                    <p class="sh-pra-hidden">
                        Disembunyikan:
                        @foreach ($menu['hidden'] as $component)
                            @if ($canEdit)
                                <button type="button" class="sh-pra-edit is-quiet" wire:click="mountAction('atur', {{ \Illuminate\Support\Js::from(['recipe' => $component['recipe_id'], 'key' => $component['key']]) }})">{{ $component['default_name'] }}</button>@if (! $loop->last),@endif
                            @else
                                {{ $component['default_name'] }}@if (! $loop->last),@endif
                            @endif
                        @endforeach
                    </p>
                @endif

                @if ($menu['issues'] !== [])
                    <ul class="sh-bd-issues">
                        @foreach ($menu['issues'] as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @empty
            <p class="sh-bd-note">SPK ini belum punya menu.</p>
        @endforelse
    </div>
</x-filament-panels::page>
