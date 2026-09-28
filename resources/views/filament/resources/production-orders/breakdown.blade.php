@php
    $order = $this->getOrder();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $order->title ?: $order->number }} · {{ $order->production_date?->translatedFormat('d M Y') }}</x-slot>
        <x-slot name="description">
            Seluruh menu di SPK ini dipecah sampai bahan mentah sesuai resepnya (sub-resep ikut diurai), lalu direkap per bahan
            dan dicocokkan dengan stok di Kartu Stok.
        </x-slot>

        @include('partials.material-breakdown', ['breakdown' => $this->getBreakdown(), 'matchUrl' => $this->getMatchUrl()])
    </x-filament::section>
</x-filament-panels::page>
