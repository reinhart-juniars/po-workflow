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
          nav.scrollTop -= box.top - item.top + 16;
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
