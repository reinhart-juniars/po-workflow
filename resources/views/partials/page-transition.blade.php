{{--
  Animasi perpindahan menu (cross-document View Transitions; Chrome/Edge 126+,
  browser lain tetap berpindah halaman biasa tanpa animasi).

  - Sidebar & bilah atas diam; hanya area konten yang bergeser.
  - Arah geser mengikuti urutan menu: ke menu di bawahnya/tab di kanannya
    konten masuk dari kanan, ke menu di atasnya dari kiri.
  - Sorotan menu aktif dan tab aplikasi meluncur ke posisi barunya.

  Dipasang inline di <head> layout Blade dan panel Filament (render hook),
  bukan di shell.css: di mode dev Vite menyajikan CSS dari origin lain dan
  opt-in @view-transition-nya tidak terbaca, sehingga transisi selalu batal.
--}}
<link rel="expect" href="#sh-page-end" blocking="render">
<style>
  @view-transition {
    navigation: auto;
  }

  .shell-sidebar,
  .fi-main-sidebar {
    view-transition-name: sh-sidebar;
  }

  .shell-topbar,
  .fi-topbar {
    view-transition-name: sh-topbar;
  }

  /* Hanya sorotan di daftar menu -- tab aplikasi versi mobile juga memakai
     .shell-item-active, dan nama kembar membatalkan transisi. */
  .shell-group .shell-item-active,
  .fi-sidebar-item.fi-active > .fi-sidebar-item-button {
    view-transition-name: sh-nav-active;
  }

  .sh-tab.is-active {
    view-transition-name: sh-app-tab;
  }

  /* Bagian yang diam langsung tampil dalam keadaan barunya. */
  ::view-transition-old(sh-sidebar),
  ::view-transition-old(sh-topbar) {
    display: none;
  }

  ::view-transition-new(sh-sidebar),
  ::view-transition-new(sh-topbar),
  ::view-transition-group(sh-sidebar),
  ::view-transition-group(sh-topbar) {
    animation: none;
  }

  /* Konten: yang lama bergeser keluar cepat, yang baru masuk sedikit lebih
     lambat dengan ease-out supaya terasa "mendarat". */
  ::view-transition-old(root) {
    animation: sh-slide-out-to-left 150ms cubic-bezier(0.4, 0, 1, 1) both;
  }

  ::view-transition-new(root) {
    animation: sh-slide-in-from-right 280ms cubic-bezier(0.16, 1, 0.3, 1) both;
  }

  html:active-view-transition-type(back)::view-transition-old(root) {
    animation-name: sh-slide-out-to-right;
  }

  html:active-view-transition-type(back)::view-transition-new(root) {
    animation-name: sh-slide-in-from-left;
  }

  /* Halaman yang sama dimuat ulang (cari, filter, paging): isi cukup
     berganti tanpa geser, supaya mengetik di kotak cari tidak "melompat". */
  html:active-view-transition-type(same)::view-transition-old(root),
  html:active-view-transition-type(same)::view-transition-new(root) {
    animation: none;
  }

  ::view-transition-group(sh-nav-active),
  ::view-transition-group(sh-app-tab) {
    animation-duration: 280ms;
    animation-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
  }

  @keyframes sh-slide-in-from-right {
    from { opacity: 0; transform: translateX(28px); }
  }

  @keyframes sh-slide-in-from-left {
    from { opacity: 0; transform: translateX(-28px); }
  }

  @keyframes sh-slide-out-to-left {
    to { opacity: 0; transform: translateX(-28px); }
  }

  @keyframes sh-slide-out-to-right {
    to { opacity: 0; transform: translateX(28px); }
  }

  @media (prefers-reduced-motion: reduce) {
    ::view-transition-group(*),
    ::view-transition-old(*),
    ::view-transition-new(*) {
      animation: none !important;
    }
  }
</style>
<script>
  (() => {
    // Posisi halaman dalam urutan menu: tab aplikasi, lalu item sidebar.
    const position = () => {
      const tabs = [...document.querySelectorAll('.sh-tab')];
      const items = [...document.querySelectorAll('.shell-group .shell-item, .fi-main-sidebar .fi-sidebar-item')];
      const app = tabs.findIndex((tab) => tab.classList.contains('is-active'));
      const item = items.findIndex((el) => el.classList.contains('shell-item-active') || el.classList.contains('fi-active'));

      return app * 1000 + item;
    };

    addEventListener('pageswap', (event) => {
      if (!event.viewTransition) {
        return;
      }

      try {
        sessionStorage.setItem('sh-nav-position', String(position()));
        sessionStorage.setItem('sh-nav-path', location.pathname);
      } catch (e) {
        // tanpa sessionStorage arah bawaan (ke kanan) dipakai
      }
    });

    addEventListener('pagereveal', (event) => {
      if (!event.viewTransition) {
        return;
      }

      let from = null;
      let fromPath = null;

      try {
        from = sessionStorage.getItem('sh-nav-position');
        fromPath = sessionStorage.getItem('sh-nav-path');
      } catch (e) {
        // abaikan
      }

      if (fromPath === location.pathname) {
        event.viewTransition.types.add('same');
      } else {
        event.viewTransition.types.add(from !== null && position() < Number(from) ? 'back' : 'forward');
      }
    });
  })();
</script>
