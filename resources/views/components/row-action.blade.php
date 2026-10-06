{{--
  Aksi baris tabel berupa ikon (label jadi tooltip), sama dengan aksi tabel
  panel Filament (EditAction/DeleteAction::configureUsing di AdminPanelProvider).

  Pemakaian:
    <x-row-action kind="edit" :href="route('...edit', $row)" />
    <x-row-action kind="delete" :action="route('...destroy', $row)" confirm="Hapus data ini?" />
    <x-row-action icon="heroicon-m-key" label="Reset password" tone="warning"
                  :action="route('...')" method="PATCH" confirm="..." />

  - href   -> tautan (<a>).
  - action -> form POST lengkap dengan @csrf, @method, dan konfirmasi.
  - tanpa keduanya -> <button type="submit"> untuk dipakai di dalam form sendiri.
--}}
@props([
    'kind' => null,
    'icon' => null,
    'label' => null,
    'tone' => null,
    'href' => null,
    'action' => null,
    'method' => 'DELETE',
    'confirm' => null,
])

@php
    // Preset: [ikon, label, warna]
    $presets = [
        'edit' => ['heroicon-m-pencil-square', 'Edit', 'primary'],
        'delete' => ['heroicon-m-trash', 'Hapus', 'danger'],
    ];

    [$presetIcon, $presetLabel, $presetTone] = $presets[$kind] ?? [null, null, 'primary'];

    $icon ??= $presetIcon;
    $label ??= $presetLabel;
    $tone ??= $presetTone;

    // Nama kelas ditulis utuh: Tailwind hanya menyertakan kelas @layer
    // components yang terbaca apa adanya di file sumber.
    $tones = [
        'primary' => 'row-action-primary',
        'danger' => 'row-action-danger',
        'warning' => 'row-action-warning',
        'success' => 'row-action-success',
        'gray' => 'row-action-gray',
    ];

    $classes = 'row-action '.($tones[$tone] ?? $tones['primary']);
@endphp

@if ($href)
  <a href="{{ $href }}" {{ $attributes->class($classes) }} aria-label="{{ $label }}" data-tooltip="{{ $label }}">
    @svg($icon, 'h-5 w-5')
  </a>
@elseif ($action)
  <form method="POST" action="{{ $action }}" class="inline-flex"
        @if ($confirm) onsubmit="return confirm(@js($confirm))" @endif>
    @csrf
    @if (strtoupper($method) !== 'POST')
      @method($method)
    @endif
    <button type="submit" {{ $attributes->class($classes) }} aria-label="{{ $label }}" data-tooltip="{{ $label }}">
      @svg($icon, 'h-5 w-5')
    </button>
  </form>
@else
  <button {{ $attributes->merge(['type' => 'submit'])->class($classes) }} aria-label="{{ $label }}" data-tooltip="{{ $label }}">
    @svg($icon, 'h-5 w-5')
  </button>
@endif
