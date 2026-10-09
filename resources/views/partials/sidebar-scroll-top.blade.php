{{-- Sidebar selalu mulai dari paling atas setiap halaman dibuka, di semua
     aplikasi: nama aplikasi dan seksi pertama selalu terlihat.

     Diletakkan di akhir <nav> sidebar (layout Blade dan panel Filament) supaya
     berlaku sebelum halaman pertama kali digambar.

     Filament punya skrip sendiri yang menggulir menu aktif ke tengah layar
     (10 ms setelah DOMContentLoaded, lihat vendor/filament/filament/resources/
     views/components/layout/index.blade.php); panggilan itu dibatalkan di sini.
     Satu-satunya pengecualian: menu aktif yang berada di bawah layar digulir
     secukupnya supaya tetap terlihat.

     $app: kunci aplikasi (admin, inventory, ...). --}}
<script data-sidebar-top="{{ $app }}">
  (() => {
    const nav = document.currentScript.closest('nav');

    if (!nav) {
      return;
    }

    // Posisi gulir yang dulu disimpan per tab tidak dipakai lagi.
    try {
      sessionStorage.removeItem('sh-sidebar-scroll:{{ $app }}');
    } catch (e) {
      // sessionStorage bisa ditolak (mode privat); tidak masalah.
    }

    const toTop = () => {
      nav.scrollTop = 0;

      const active = nav.querySelector('[aria-current="page"], .fi-sidebar-item.fi-active');

      if (active) {
        const box = nav.getBoundingClientRect();
        const item = active.getBoundingClientRect();

        if (item.bottom > box.bottom) {
          nav.scrollTop += item.bottom - box.bottom + 16;
        }
      }
    };

    toTop();

    // Di panel Filament, tinggi sidebar baru terkunci setelah Alpine memasang
    // kelasnya. Diulang saat Filament hendak menggulir menu aktif ke tengah
    // (panggilan itu diganti dengan ini) dan, sebagai cadangan, saat Alpine siap.
    nav.scrollTo = () => {
      delete nav.scrollTo;
      toTop();
    };
    document.addEventListener('alpine:initialized', toTop);
    window.addEventListener('load', () => setTimeout(() => delete nav.scrollTo, 100));
  })();
</script>
