{{--
  Nama menu beserta badge asal datanya (V2 = menu jual dari PO Workflow v2,
  MMR = resep dari Master Menu Revamp). Data yang dibuat di 3S ONE tampil
  tanpa badge.
--}}
<span style="display: inline-flex; flex-wrap: wrap; align-items: center; gap: 6px;">
    <span>{{ $text }}</span>
    @if ($origin)
        <x-filament::badge :color="$origin" size="sm" :tooltip="$tooltip">{{ $label }}</x-filament::badge>
    @endif
</span>
