{{-- Asal sebuah Penjualan Barang Sisa: retur customer mana, kapan. --}}
@php
    $source = $item->sourceSalesActualItem;
@endphp
@if ($source && $item->leftover_component_id)
    Komponen rincian {{ $source->item_name }}, retur {{ $source->salesActual?->customer?->name ?? '-' }}
    {{ $source->salesActual?->submitted_at?->format('d M Y') }}
@elseif ($source)
    Barang Sisa dari retur {{ $source->salesActual?->customer?->name ?? '-' }}
    {{ $source->salesActual?->submitted_at?->format('d M Y') }}
@else
    Barang Sisa
@endif
