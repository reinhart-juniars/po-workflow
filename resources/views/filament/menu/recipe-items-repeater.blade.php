{{--
  Rincian Bahan resep, bergaya tabel Master Menu Revamp: baris dikelompokkan
  per Kelompok (komponen, mis. "Ayam Balado") dengan jumlah baris dan
  subtotalnya, dan setiap baris terlipat menampilkan jumlah, harga satuan,
  total, dan sumber biayanya. Klik baris untuk membuka isiannya.

  Turunan dari view repeater Filament (forms::components.repeater.index).
  Judul kelompok adalah <li> tanpa x-sortable-item, jadi tidak ikut diseret
  dan tidak masuk urutan reorder (Sortable hanya membaca [x-sortable-item]).
  Angkanya dihitung dari isian form (belum tentu tersimpan) dengan
  perhitungan HPP yang sama (RecipeResource::linePreview). Kelas sh-rcp-*
  ada di resources/css/shell.css.
--}}
@php
    use App\Filament\Menu\Resources\RecipeResource;
    use Filament\Forms\Components\Actions\Action;
    use Filament\Support\Enums\Alignment;

    $containers = $getChildComponentContainers();

    $addAction = $getAction($getAddActionName());
    $cloneAction = $getAction($getCloneActionName());
    $collapseAllAction = $getAction($getCollapseAllActionName());
    $expandAllAction = $getAction($getExpandAllActionName());
    $deleteAction = $getAction($getDeleteActionName());
    $moveDownAction = $getAction($getMoveDownActionName());
    $moveUpAction = $getAction($getMoveUpActionName());
    $reorderAction = $getAction($getReorderActionName());
    $extraItemActions = $getExtraItemActions();

    $isAddable = $isAddable();
    $isCloneable = $isCloneable();
    $isCollapsible = $isCollapsible();
    $isDeletable = $isDeletable();
    $isReorderableWithButtons = $isReorderableWithButtons();
    $isReorderableWithDragAndDrop = $isReorderableWithDragAndDrop();

    $collapseAllActionIsVisible = $isCollapsible && $collapseAllAction->isVisible();
    $expandAllActionIsVisible = $isCollapsible && $expandAllAction->isVisible();

    $statePath = $getStatePath();
    $recipeId = $getRecord()?->getKey();

    $rupiah = fn ($value) => $value === null ? '-' : 'Rp '.number_format((float) $value, 2, ',', '.');
    $sources = ['ingredient' => 'Bahan', 'recipe' => 'Sub-Menu', 'snapshot' => 'Harga Manual', 'unmatched' => 'Belum Tertaut'];

    // Satu putaran hitung untuk semua baris. Seperti Master Menu Revamp, satu
    // Kelompok = satu blok berisi SEMUA barisnya walau di data urutannya
    // berselang (mis. minyak goreng ditambahkan belakangan); blok diurutkan
    // menurut kemunculan pertamanya, baris di dalamnya menurut urutan asli.
    // Menyeret baris menyimpan urutan yang tampil ini.
    $rows = [];
    $groups = [];

    foreach ($containers as $uuid => $container) {
        $state = $container->getRawState();
        $section = trim((string) ($state['section'] ?? ''));
        $preview = RecipeResource::linePreview($state, $recipeId);
        $rows[$uuid] = ['state' => $state, 'preview' => $preview];

        $groups[$section] ??= ['name' => $section, 'count' => 0, 'total' => 0.0, 'issues' => 0, 'uuids' => []];
        $groups[$section]['count']++;
        $groups[$section]['total'] += (float) $preview['line_total'];
        $groups[$section]['issues'] += $preview['issue'] !== null ? 1 : 0;
        $groups[$section]['uuids'][] = $uuid;
    }

    // Baris tanpa kelompok selalu di bawah, tempat baris baru muncul.
    if (isset($groups[''])) {
        $ungrouped = $groups[''];
        unset($groups['']);
        $groups[''] = $ungrouped;
    }

    $ordered = [];
    $groupOf = [];

    foreach ($groups as $key => $group) {
        foreach ($group['uuids'] as $uuid) {
            $ordered[] = $uuid;
            $groupOf[$uuid] = $key;
        }
    }

    $hasSections = collect($groups)->contains(fn (array $group) => $group['name'] !== '');
    $grandTotal = array_sum(array_column($groups, 'total'));
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{}"
        {{
            $attributes
                ->merge($getExtraAttributes(), escape: false)
                ->class(['fi-fo-repeater grid gap-y-3'])
        }}
    >
        @if ($collapseAllActionIsVisible || $expandAllActionIsVisible)
            <div
                @class([
                    'flex gap-x-3',
                    'hidden' => count($containers) < 2,
                ])
            >
                @if ($collapseAllActionIsVisible)
                    <span x-on:click="$dispatch('repeater-collapse', '{{ $statePath }}')">
                        {{ $collapseAllAction }}
                    </span>
                @endif

                @if ($expandAllActionIsVisible)
                    <span x-on:click="$dispatch('repeater-expand', '{{ $statePath }}')">
                        {{ $expandAllAction }}
                    </span>
                @endif
            </div>
        @endif

        @if (count($containers))
            <div class="sh-rcp-head" aria-hidden="true">
                <span>Bahan</span>
                <span class="num">Jumlah</span>
                <span class="num">Harga Satuan</span>
                <span class="num">Total</span>
                <span>Sumber</span>
            </div>

            <ul>
                <x-filament::grid
                    :default="1"
                    :wire:end.stop="'mountFormComponentAction(\'' . $statePath . '\', \'reorder\', { items: $event.target.sortable.toArray() })'"
                    x-sortable
                    :data-sortable-animation-duration="$getReorderAnimationDuration()"
                    class="items-start gap-2"
                >
                    @foreach ($ordered as $uuid)
                        @php
                            $item = $containers[$uuid];
                            $state = $rows[$uuid]['state'];
                            $preview = $rows[$uuid]['preview'];
                            $group = $groups[$groupOf[$uuid]];
                            $visibleExtraItemActions = array_filter(
                                $extraItemActions,
                                fn (Action $action): bool => $action(['item' => $uuid])->isVisible(),
                            );
                            $cloneAction = $cloneAction(['item' => $uuid]);
                            $cloneActionIsVisible = $isCloneable && $cloneAction->isVisible();
                            $deleteAction = $deleteAction(['item' => $uuid]);
                            $deleteActionIsVisible = $isDeletable && $deleteAction->isVisible();
                            $moveDownAction = $moveDownAction(['item' => $uuid])->disabled($loop->last);
                            $moveDownActionIsVisible = $isReorderableWithButtons && $moveDownAction->isVisible();
                            $moveUpAction = $moveUpAction(['item' => $uuid])->disabled($loop->first);
                            $moveUpActionIsVisible = $isReorderableWithButtons && $moveUpAction->isVisible();
                            $reorderActionIsVisible = $isReorderableWithDragAndDrop && $reorderAction->isVisible();
                        @endphp

                        @if ($hasSections && $group['uuids'][0] === $uuid)
                            <li
                                wire:key="{{ $this->getId() }}.{{ $statePath }}.group.{{ $uuid }}"
                                class="sh-rcp-group"
                            >
                                <span class="sh-rcp-group-name">{{ $group['name'] !== '' ? $group['name'] : 'Tanpa kelompok' }}</span>
                                <span class="sh-rcp-group-meta">
                                    {{ $group['count'] }} baris
                                    @if ($group['issues'] > 0)
                                        · <span class="is-bad">{{ $group['issues'] }} belum terhitung</span>
                                    @endif
                                </span>
                                <span class="sh-rcp-group-total">{{ $rupiah($group['total']) }}</span>
                            </li>
                        @endif

                        <li
                            wire:ignore.self
                            wire:key="{{ $this->getId() }}.{{ $item->getStatePath() }}.{{ $field::class }}.item"
                            x-data="{
                                isCollapsed: @js($isCollapsed($item)),
                            }"
                            x-on:expand="isCollapsed = false"
                            x-on:repeater-expand.window="$event.detail === '{{ $statePath }}' && (isCollapsed = false)"
                            x-on:repeater-collapse.window="$event.detail === '{{ $statePath }}' && (isCollapsed = true)"
                            x-sortable-item="{{ $uuid }}"
                            @class([
                                'fi-fo-repeater-item sh-rcp-item divide-y divide-gray-100 rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5',
                                'is-issue' => $preview['issue'] !== null,
                            ])
                            x-bind:class="{ 'fi-collapsed': isCollapsed }"
                        >
                            <div
                                @if ($isCollapsible)
                                    x-on:click.stop="isCollapsed = !isCollapsed"
                                @endif
                                @class([
                                    'fi-fo-repeater-item-header flex items-center gap-x-3 px-3 py-2',
                                    'cursor-pointer select-none' => $isCollapsible,
                                ])
                            >
                                @if ($reorderActionIsVisible || $moveUpActionIsVisible || $moveDownActionIsVisible)
                                    <ul class="flex items-center gap-x-2">
                                        @if ($reorderActionIsVisible)
                                            <li x-sortable-handle x-on:click.stop>
                                                {{ $reorderAction }}
                                            </li>
                                        @endif

                                        @if ($moveUpActionIsVisible || $moveDownActionIsVisible)
                                            <li x-on:click.stop class="flex items-center justify-center">
                                                {{ $moveUpAction }}
                                            </li>

                                            <li x-on:click.stop class="flex items-center justify-center">
                                                {{ $moveDownAction }}
                                            </li>
                                        @endif
                                    </ul>
                                @endif

                                <div class="sh-rcp-row">
                                    <div class="sh-rcp-name">
                                        <span class="sh-rcp-raw">{{ filled($state['raw_name'] ?? null) ? $state['raw_name'] : 'Baris baru' }}</span>
                                        @if ($preview['linked_name'])
                                            <span class="sh-rcp-sub">→ {{ $preview['linked_name'] }}</span>
                                        @endif
                                        @if ($preview['issue'])
                                            <span class="sh-rcp-issue">{{ $preview['issue'] }}</span>
                                        @endif
                                    </div>
                                    <span class="num" data-label="Jumlah">{{ RecipeResource::formatQty($state['qty'] ?? null) }} {{ $state['unit'] ?? '' }}</span>
                                    <span class="num" data-label="Harga">{{ $rupiah($preview['unit_price']) }}</span>
                                    <span class="num sh-rcp-total" data-label="Total">{{ $rupiah($preview['line_total']) }}</span>
                                    <span><span class="sh-rcp-src is-{{ $preview['source'] }}">{{ $sources[$preview['source']] ?? $preview['source'] }}</span></span>
                                </div>

                                @if ($cloneActionIsVisible || $deleteActionIsVisible || $isCollapsible || $visibleExtraItemActions)
                                    <ul class="ms-auto flex items-center gap-x-2">
                                        @foreach ($visibleExtraItemActions as $extraItemAction)
                                            <li x-on:click.stop>
                                                {{ $extraItemAction(['item' => $uuid]) }}
                                            </li>
                                        @endforeach

                                        @if ($cloneActionIsVisible)
                                            <li x-on:click.stop>
                                                {{ $cloneAction }}
                                            </li>
                                        @endif

                                        @if ($deleteActionIsVisible)
                                            <li x-on:click.stop>
                                                {{ $deleteAction }}
                                            </li>
                                        @endif

                                        @if ($isCollapsible)
                                            <li
                                                class="relative transition"
                                                x-on:click.stop="isCollapsed = !isCollapsed"
                                                x-bind:class="{ '-rotate-180': isCollapsed }"
                                            >
                                                <div
                                                    class="transition"
                                                    x-bind:class="{ 'opacity-0 pointer-events-none': isCollapsed }"
                                                >
                                                    {{ $getAction('collapse') }}
                                                </div>

                                                <div
                                                    class="absolute inset-0 rotate-180 transition"
                                                    x-bind:class="{ 'opacity-0 pointer-events-none': ! isCollapsed }"
                                                >
                                                    {{ $getAction('expand') }}
                                                </div>
                                            </li>
                                        @endif
                                    </ul>
                                @endif
                            </div>

                            <div x-show="! isCollapsed" class="fi-fo-repeater-item-content p-4">
                                {{ $item }}
                            </div>
                        </li>
                    @endforeach
                </x-filament::grid>
            </ul>

            <div class="sh-rcp-foot">
                <span>Total bahan ({{ count($containers) }} baris)</span>
                <span class="sh-rcp-total">{{ $rupiah($grandTotal) }}</span>
            </div>
        @endif

        @if ($isAddable && $addAction->isVisible())
            <div
                @class([
                    'flex',
                    match ($getAddActionAlignment()) {
                        Alignment::Start, Alignment::Left => 'justify-start',
                        Alignment::Center, null => 'justify-center',
                        Alignment::End, Alignment::Right => 'justify-end',
                        default => 'justify-center',
                    },
                ])
            >
                {{ $addAction }}
            </div>
        @endif
    </div>
</x-dynamic-component>
