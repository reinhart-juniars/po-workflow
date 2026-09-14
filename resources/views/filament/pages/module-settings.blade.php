<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
    </form>

    <x-filament::section>
        <x-slot name="heading">Catatan</x-slot>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Setiap perubahan dicatat ke Audit Log (siapa, kapan, dari nilai apa ke apa).
            Awalan nomor dokumen hanya berlaku untuk dokumen yang dibuat setelah diubah;
            nomor yang sudah terbit tidak berubah.
        </p>
    </x-filament::section>
</x-filament-panels::page>
