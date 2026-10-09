{{-- Posisi gulir sidebar dipertahankan antar halaman, per aplikasi.

     Diletakkan di akhir <nav> sidebar (layout Blade dan panel Filament) supaya
     posisinya dipulihkan sebelum halaman pertama kali digambar -- menu yang
     baru diklik tetap di tempatnya, tidak melompat ke atas atau ke tengah.

     Filament punya skrip sendiri yang menggulir menu aktif ke tengah layar
     (10 ms setelah DOMContentLoaded, lihat vendor/filament/filament/resources/
     views/components/layout/index.blade.php); panggilan itu dibatalkan di sini.

     $app: kunci aplikasi (admin, inventory, ...) -- tiap sidebar punya posisi sendiri. --}}
<script>
  (() => {
    const nav = document.currentScript.closest('nav');

    if (!nav) {
      return;
    }

    const key = 'sh-sidebar-scroll:{{ $app }}';

    const restore = () => {
      try {
        const saved = sessionStorage.getItem(key);

        if (saved !== null) {
          nav.scrollTop = Number(saved) || 0;
        }
      } catch (e) {
        // sessionStorage bisa ditolak (mode privat); sidebar mulai dari atas.
      }

      // Menu aktif tetap harus terlihat, mis. saat datang dari tautan di konten.
      const active = nav.querySelector('[aria-current="page"], .fi-sidebar-item.fi-active');

      if (active) {
        const box = nav.getBoundingClientRect();
        const item = active.getBoundingClientRect();

        if (item.top < box.top) {
          // Gulir ke atas sampai judul seksinya ikut terlihat. Seksi pertama
          // (mis. Ringkasan › Dashboard) berarti kembali ke paling atas, jadi
          // nama aplikasi di atas menu tidak terpotong.
          const groupSelector = 'details.shell-group, .fi-sidebar-group';
          const group = active.closest(groupSelector);
          const isFirst = group && group === nav.querySelector(groupSelector);
          const top = group ? group.getBoundingClientRect().top : item.top;

          nav.scrollTop = isFirst ? 0 : Math.max(0, nav.scrollTop - (box.top - top) - 16);

          // Seksi yang lebih tinggi dari sidebar: cukup menu aktifnya yang terlihat.
          if (active.getBoundingClientRect().bottom > box.bottom) {
            nav.scrollTop += active.getBoundingClientRect().bottom - box.bottom + 16;
          }
        } else if (item.bottom > box.bottom) {
          nav.scrollTop += item.bottom - box.bottom + 16;
        }
      }
    };

    restore();

    // Di panel Filament, tinggi sidebar baru terkunci setelah Alpine memasang
    // kelasnya, jadi pemulihan di atas bisa terpotong ke 0. Diulang saat
    // Filament hendak menggulir menu aktif ke tengah (panggilan itu diganti
    // dengan pemulihan ini) dan, sebagai cadangan, saat Alpine siap.
    nav.scrollTo = () => {
      delete nav.scrollTo;
      restore();
    };
    document.addEventListener('alpine:initialized', restore);
    window.addEventListener('load', () => setTimeout(() => delete nav.scrollTo, 100));

    const save = () => {
      try {
        sessionStorage.setItem(key, String(nav.scrollTop));
      } catch (e) {
        // abaikan
      }
    };

    nav.addEventListener('click', save);
    window.addEventListener('pagehide', save);
  })();
</script>
