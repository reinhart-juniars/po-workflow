{{-- Asal sebuah Penjualan Barang Sisa: retur customer mana, kapan. --}}
@php
    $source = $item->sourceSalesActualItem;
@endphp
@if ($source)
    Barang Sisa dari retur {{ $source->salesActual?->customer?->name ?? '-' }}
    {{ $source->salesActual?->submitted_at?->format('d M Y') }}
@else
    Barang Sisa
@endif
