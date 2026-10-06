<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $this->getOrder()->title ?: $this->getOrder()->number }}</x-slot>
        <x-slot name="description">
            {{ $this->getOrder()->production_date->format('d/m/Y') }} {{ $this->getOrder()->production_time }}.
            Salin dari template menu lalu sunting; centang yang sudah selesai.
        </x-slot>

        {{ $this->form }}
    </x-filament::section>
</x-filament-panels::page>
