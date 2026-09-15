{{-- Tanda 3S BCS: kotak gelap datar, tanpa gradasi. $size: sm|md|lg --}}
@php
    $box = ['sm' => 'h-9 w-9 rounded-xl', 'md' => 'h-10 w-10 rounded-2xl', 'lg' => 'h-12 w-12 rounded-2xl'][$size ?? 'md'];
    $top = ['sm' => 'text-[7px]', 'md' => 'text-[7px]', 'lg' => 'text-[9px]'][$size ?? 'md'];
    $bottom = ['sm' => 'text-[9px]', 'md' => 'text-[10px]', 'lg' => 'text-xs'][$size ?? 'md'];
@endphp
<span class="flex {{ $box }} shrink-0 flex-col items-center justify-center bg-slate-950 text-white ring-1 ring-inset ring-white/10 leading-none" aria-hidden="true">
  <span class="{{ $top }} font-semibold uppercase tracking-[0.24em] text-cyan-300">3S</span>
  <span class="mt-0.5 {{ $bottom }} font-bold tracking-[0.16em]">BCS</span>
</span>
