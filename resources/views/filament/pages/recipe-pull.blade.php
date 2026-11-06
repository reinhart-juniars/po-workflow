@php
    $breakdown = $this->getBreakdown();
    $matchUrl = auth()->user()?->can('recipe.view') ? route('filament.menu.pages.pencocokan-menu') : null;
@endphp

<x-filament-panels::page>
    <form wire:submit="calculate">
        {{ $this->form }}

        <div style="margin-top: 16px">
            <x-filament::button type="submit" icon="heroicon-m-calculator">Tarik Bahan</x-filament::button>
        </div>
    </form>

    @if ($breakdown)
        <x-filament::section>
            <x-slot name="heading">Kebutuhan bahan</x-slot>
            @include('partials.material-breakdown', ['breakdown' => $breakdown, 'matchUrl' => $matchUrl, 'standalone' => true])
        </x-filament::section>
    @endif
</x-filament-panels::page>
