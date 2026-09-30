{{--
  Baris Bahan Baku Terpakai di tampilan web Laba Rugi / Laporan Final. Bila ada
  rincian (Barang Hilang, Temuan, Sisa Dibuang), barisnya jadi tombol: rincian
  tersembunyi (atribut hidden) sampai diklik. Cangkang Blade tidak memuat
  Alpine, jadi pembukanya satu listener kecil di dokumen. Ekspor PDF/Excel
  tidak memakai partial ini -- di kertas rinciannya selalu tampil.

  Parameter: $profitLoss, $fmt.
--}}
@php
  $hasHppDetails = \App\Support\HppDetailRows::from($profitLoss)->isNotEmpty();
@endphp
@if ($hasHppDetails)
  @once
    @push('styles')
      <style>
        tr[data-hpp-toggle] { cursor: pointer; }
        tr[data-hpp-toggle]:hover td { background: rgb(203 213 225); }
        tr[data-hpp-toggle]:focus-visible { outline: 2px solid rgb(52 85 219); outline-offset: -2px; }
        tr[data-hpp-toggle] .hpp-chevron { transition: transform 200ms ease; }
        tr[data-hpp-toggle][aria-expanded="true"] .hpp-chevron { transform: rotate(180deg); }
        tr[data-hpp-toggle] .hpp-label-close,
        tr[data-hpp-toggle][aria-expanded="true"] .hpp-label-open { display: none; }
        tr[data-hpp-toggle][aria-expanded="true"] .hpp-label-close { display: inline; }
      </style>
      <script>
        // Buka-tutup rincian HPP: baris data-hpp-detail sesudah baris toggle di tabel yang sama.
        (() => {
          const toggle = (row) => {
            const open = row.getAttribute('aria-expanded') !== 'true';
            row.setAttribute('aria-expanded', String(open));
            row.closest('table').querySelectorAll('tr[data-hpp-detail]').forEach((detail) => { detail.hidden = !open; });
          };
          document.addEventListener('click', (event) => {
            const row = event.target.closest('tr[data-hpp-toggle]');
            if (row) toggle(row);
          });
          document.addEventListener('keydown', (event) => {
            const row = event.target.closest?.('tr[data-hpp-toggle]');
            if (row && (event.key === 'Enter' || event.key === ' ')) {
              event.preventDefault();
              toggle(row);
            }
          });
        })();
      </script>
    @endpush
  @endonce
@endif
<tr
  class="subtotal-row"
  @if ($hasHppDetails)
    data-hpp-toggle
    role="button"
    tabindex="0"
    aria-expanded="false"
    title="Klik untuk melihat rincian"
  @endif
>
  <td class="label-cell">
    Bahan Baku Terpakai
    @if ($hasHppDetails)
      <span class="ml-2 inline-flex items-center gap-1 rounded-md bg-white px-1.5 py-0.5 text-[11px] font-semibold text-brand-700 shadow-sm">
        <svg class="hpp-chevron h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
        </svg>
        <span class="hpp-label-open">Lihat rincian</span>
        <span class="hpp-label-close">Tutup rincian</span>
      </span>
    @endif
  </td>
  <td class="value-cell"></td>
  <td class="value-cell">{{ $fmt((float) $profitLoss['bahanBakuTerpakai']) }}</td>
</tr>
@include('partials.pl-hpp-detail-rows', ['profitLoss' => $profitLoss, 'fmt' => $fmt, 'labelClass' => 'indent', 'valueClass' => 'value-cell', 'collapsible' => true])
