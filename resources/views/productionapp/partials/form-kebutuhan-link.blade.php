{{-- Tautan ke Form Kebutuhan (modul inventory) untuk slot SPK sebuah PO.
     $spk: App\Models\Spk|null, $compact: true = tautan teks, false = tombol --}}
@php
    $order = $spk?->productionOrder;
    $bolehLihat = auth()->user()?->can('production.view');
    $bolehSusun = auth()->user()?->can('production.manage');
    $kelas = ($compact ?? false) ? 'text-sm font-semibold text-emerald-700 hover:text-emerald-600' : 'btn-ghost mt-2 w-full justify-center text-emerald-700';
@endphp
@if ($spk && $order && $bolehLihat)
  <a href="{{ route('filament.admin.resources.production-orders.kebutuhan', ['record' => $order]) }}" class="{{ $kelas }}">
    Form Kebutuhan {{ $order->number }}
  </a>
@elseif ($spk && $bolehSusun)
  <form method="POST" action="{{ route('productionapp.spk.production-order', $spk) }}" class="inline">
    @csrf
    <button type="submit" class="{{ $kelas }}">Susun Form Kebutuhan</button>
  </form>
@endif
