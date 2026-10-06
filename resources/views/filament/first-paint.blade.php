{{--
  Frame pertama panel Filament sama dengan keadaannya setelah Alpine aktif.

  Tanpa ini, setiap halaman panel digambar kosong dulu lalu "muncul" setelah
  Alpine berjalan -- terasa seperti halaman di-refresh, dan animasi geser
  antar menu (partials/page-transition) menganimasikan layar kosong:
  - Livewire menyuntik `[x-cloak] { display: none !important }` yang
    mengalahkan aturan Filament `[x-cloak='-lg']` (sembunyi hanya di layar
    kecil), sehingga sidebar ikut hilang di desktop;
  - .fi-main-ctn diberi opacity-0 sampai Alpine memasang `opacity: 1`;
  - lebar & posisi sticky sidebar baru dipasang Alpine (x-bind:class).

  Aman untuk panel ini karena sidebar desktop tidak bisa dilipat, jadi tidak
  ada state Alpine yang perlu ditunggu. Bila kelak memakai
  ->sidebarCollapsibleOnDesktop(), aturan ini harus ditinjau ulang.
--}}
<style>
  .fi-main-ctn.opacity-0 {
    display: flex;
    opacity: 1;
  }

  @media (min-width: 1024px) {
    .fi-main-sidebar[x-cloak='-lg'] {
      display: flex !important;
      position: sticky;
      width: var(--sidebar-width);
    }
  }
</style>
