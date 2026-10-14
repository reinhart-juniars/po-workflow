/* ==========================================================================
   W3S Catering — Landing Page
   --------------------------------------------------------------------------
   GANTI SATU BARIS DI BAWAH INI KALAU NOMOR WA BERUBAH.
   Format: kode negara tanpa "+" dan tanpa "0" di depan.
   Contoh: 0812 3577 3362  ->  6281235773362
   ========================================================================== */
var WA_NUMBER = '6281235773362';

(function () {
  'use strict';

  /* ---------- 1. Semua tombol WhatsApp ---------------------------------- */
  // Elemen dengan .js-wa akan diberi href wa.me + pesan otomatis dari data-msg.
  // Sumber section ikut dikirim supaya klien tahu tombol mana yang paling laku.
  function buildWaLink(message) {
    var url = 'https://wa.me/' + WA_NUMBER;
    if (message) url += '?text=' + encodeURIComponent(message);
    return url;
  }

  /* Pelacakan konversi ke Google Analytics.

     Konversi situs ini adalah klik WhatsApp — dan itu MEMBAWA ORANG KELUAR ke
     wa.me. Tanpa event ini, GA4 melihat pengunjung yang berhasil menghubungi
     klien persis sama dengan pengunjung yang membuka lalu langsung menutup tab:
     dua-duanya terhitung bounce. Jadi angka yang paling ingin diketahui klien
     justru satu-satunya yang tidak terekam secara default.

     'generate_lead' adalah nama event yang direkomendasikan GA4 untuk ini, jadi
     laporannya muncul tanpa perlu konfigurasi tambahan di dashboard.

     Penjaga typeof WAJIB dipertahankan: pemblokir iklan memblokir
     googletagmanager.com dengan sangat umum, dan tanpa penjaga ini setiap klik
     tombol WhatsApp akan melempar ReferenceError. Tombolnya sendiri tetap jalan
     (href sudah terpasang sejak muat halaman), tapi konsol jadi penuh error. */
  function lacak(nama, data) {
    if (typeof gtag !== 'function') return;
    try { gtag('event', nama, data); } catch (e) { /* jangan pernah merusak klik */ }
  }

  /* Dari elemen mana tombolnya ditekan. Disimpulkan dari struktur halaman,
     bukan dari atribut data-* di tiap tombol — dengan ~20 tombol tersebar di
     tiga halaman, atribut manual pasti ada yang terlewat saat markup diubah. */
  function lokasiTombol(el) {
    if (el.closest('.site-header')) return 'header';
    if (el.closest('.sticky-cta'))  return 'sticky-cta';
    if (el.closest('.hero'))        return 'hero';
    if (el.closest('.site-footer')) return 'footer';
    if (el.closest('.crosslink'))   return 'tautan-silang';
    if (el.closest('.offer'))       return 'blok-penawaran';
    /* Pengunjung mencari menu yang tidak ada lalu menekan 'tanyakan langsung
       ke kami'. Ini sinyal paling berharga di katalog — permintaan nyata yang
       belum ada di daftar — jadi jangan biarkan jatuh ke 'lain'. */
    if (el.closest('.no-result'))   return 'katalog-kosong';
    var pkg = el.closest('.pkg');
    if (pkg) {
      var n = pkg.querySelector('.pkg__name');
      return 'paket: ' + (n ? n.textContent.trim() : '?');
    }
    var sec = el.closest('section[id]');
    return sec ? sec.id : 'lain';
  }

  /* Halaman mana — supaya klien bisa membandingkan performa dua brand. */
  var HALAMAN = document.body.classList.contains('t-3s') ? '3s'
              : (location.pathname.indexOf('wuenak-poll') !== -1 ? 'wuenak-poll' : 'utama');

  /* Baris baru di data-msg ditulis sebagai dua karakter "\n" (garis miring
     terbalik + n), dan diterjemahkan ke baris baru sungguhan DI SINI, sebelum
     encodeURIComponent.

     Jangan tulis %0A di data-msg. Itu bug yang pernah tayang: %0A sudah
     berbentuk ter-encode, lalu encodeURIComponent meng-encode-nya sekali lagi
     menjadi %250A. WhatsApp hanya men-decode satu kali, jadi yang muncul di
     kotak chat adalah teks "%0A" apa adanya — pesan tampak berantakan dan
     klien yang pertama melihatnya, bukan kami. */
  document.querySelectorAll('.js-wa').forEach(function (el) {
    var msg = (el.getAttribute('data-msg') || 'Halo W3S, saya ingin bertanya.')
      .replace(/\\n/g, '\n');
    el.setAttribute('href', buildWaLink(msg));
    el.setAttribute('target', '_blank');
    el.setAttribute('rel', 'noopener');
    el.addEventListener('click', function () {
      lacak('generate_lead', {
        method: 'whatsapp',
        link_location: lokasiTombol(el),
        page_brand: HALAMAN,
        link_text: (el.textContent || '').trim().slice(0, 60)
      });
    });
  });

  document.querySelectorAll('.js-wa-plain').forEach(function (el) {
    el.setAttribute('href', buildWaLink(''));
    el.setAttribute('target', '_blank');
    el.setAttribute('rel', 'noopener');
    el.addEventListener('click', function () {
      lacak('generate_lead', {
        method: 'whatsapp',
        link_location: lokasiTombol(el),
        page_brand: HALAMAN,
        link_text: 'nomor-polos'
      });
    });
  });

  /* ---------- 2. Form -> pesan WhatsApp terformat ----------------------- */
  var form = document.getElementById('quoteForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      var v = function (id) {
        var el = document.getElementById(id);
        return el ? el.value.trim() : '';
      };

      // validasi sederhana untuk field wajib
      var wajib = ['nama', 'acara', 'pax'];
      for (var i = 0; i < wajib.length; i++) {
        var el = document.getElementById(wajib[i]);
        if (!el.value.trim()) {
          el.focus();
          el.reportValidity ? el.reportValidity() : alert('Mohon lengkapi data yang wajib diisi.');
          return;
        }
      }

      // format tanggal jadi gaya Indonesia
      var tgl = v('tanggal');
      if (tgl) {
        var d = new Date(tgl + 'T00:00:00');
        if (!isNaN(d)) {
          tgl = d.toLocaleDateString('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
          });
        }
      }

      var baris = [
        'Halo W3S, saya ingin minta rekomendasi menu & penawaran.',
        '',
        'Nama          : ' + v('nama'),
        v('instansi') ? 'Instansi      : ' + v('instansi') : null,
        'Jenis Acara   : ' + v('acara'),
        tgl ? 'Tanggal       : ' + tgl : null,
        'Jumlah Pax    : ' + v('pax') + ' pax',
        v('budget') ? 'Budget/Pax    : ' + v('budget') : null,
        v('lokasi') ? 'Lokasi        : ' + v('lokasi') : null,
        v('catatan') ? '' : null,
        v('catatan') ? 'Catatan: ' + v('catatan') : null,
        '',
        'Terima kasih.'
      ].filter(function (x) { return x !== null; });

      lacak('generate_lead', {
        method: 'form',
        link_location: 'form-penawaran',
        page_brand: HALAMAN,
        jenis_acara: v('acara'),
        jumlah_pax: v('pax')
      });

      window.open(buildWaLink(baris.join('\n')), '_blank', 'noopener');
    });
  }

  /* ---------- 3. Barcode: tag per lokasi penempatan ---------------------

     Klien memasang barcode di tiga tempat berbeda dan ingin tahu barcode mana
     yang dipindai. Untuk itu ia membuat tiga properti Google terpisah.

     MASALAHNYA: memasang ketiga tag itu di setiap halaman TIDAK menjawab
     pertanyaannya. Kalau semuanya menyala di tiap kunjungan, ketiga properti
     akan menampilkan angka yang persis sama — total seluruh pengunjung situs.
     Tidak ada satu pun yang tahu barcode mana yang dipindai, karena halaman
     yang dibuka memang sama.

     Yang membedakan penempatan bukan tag-nya, melainkan URL di dalam
     barcode-nya. Jadi tiap barcode membawa penanda ?qr=..., dan tag khusus
     lokasi itu HANYA dimuat kalau penandanya cocok. Barulah tiap properti
     berisi kunjungan dari barcode-nya sendiri.

     Kunjungan biasa (tanpa ?qr=) tidak memuat satu pun tag tambahan — halaman
     tetap seringan sebelumnya untuk mayoritas pengunjung.

     Kalau suatu saat memang diinginkan ketiganya menyala di semua kunjungan,
     hapus penjaga "if (titik)" — tapi baca dulu paragraf kedua di atas.
     -------------------------------------------------------------------- */
  var QR_TITIK = {
    fasilitas: { id: 'AW-18385370495', nama: 'Fasilitas umum',  kunci: ['fasilitas'] },
    kendaraan: { id: 'G-RHTL2KV3BG',  nama: 'Kendaraan umum',  kunci: ['kendaraan'] },
    kemasan:   { id: 'G-MMY22EZ455',  nama: 'Barcode kemasan', kunci: ['kemasan'] }
  };

  /* Dua cara menandai penempatan, dan keduanya perlu ada.

     a. ?qr=kemasan — penanda pendek, dipakai kalau barcode dibuat dari sini.
     b. utm_source — klien SUDAH menyusun skema UTM sendiri dan memasukkannya
        ke Stream URL di Google Analytics (mis. utm_source=fasilitas_umum).
        Kalau kode ini hanya mengenali (a), URL yang sudah mereka pakai tidak
        akan memuat tag apa pun dan Google melaporkan "tag not detected".

     Pencocokan (b) memakai KATA KUNCI, bukan string persis: nama sumber di
     tiap properti bisa berbeda ejaan (fasilitas_umum / fasilitas-umum /
     FasilitasUmum) dan mencocokkan persis akan patah pada variasi pertama. */
  function cariTitik() {
    var par = new URLSearchParams(location.search);

    var q = (par.get('qr') || '').toLowerCase();
    if (QR_TITIK[q]) return { kode: q, titik: QR_TITIK[q] };

    var src = (par.get('utm_source') || '').toLowerCase();
    if (!src) return null;
    for (var k in QR_TITIK) {
      var kunci = QR_TITIK[k].kunci;
      for (var i = 0; i < kunci.length; i++) {
        if (src.indexOf(kunci[i]) !== -1) return { kode: k, titik: QR_TITIK[k] };
      }
    }
    return null;
  }

  var cocok = cariTitik();
  var qrParam = cocok ? cocok.kode : null;
  var titik = cocok ? cocok.titik : null;

  if (titik) {
    /* a. Laporkan juga ke properti UTAMA. Ini yang membuat ketiga penempatan
          bisa DIBANDINGKAN dalam satu laporan — sesuatu yang tidak mungkin
          dilakukan kalau datanya terpisah di tiga properti berbeda. */
    lacak('qr_scan', {
      qr_lokasi: qrParam,
      qr_nama: titik.nama,
      page_brand: HALAMAN
    });

    /* b. Muat tag milik lokasi itu saja. */
    var sk = document.createElement('script');
    sk.async = true;
    sk.src = 'https://www.googletagmanager.com/gtag/js?id=' + titik.id;
    document.head.appendChild(sk);

    /* gtag() hanya mengantre ke dataLayer, jadi aman dipanggil sebelum
       skripnya selesai diunduh — persis seperti snippet resmi Google. */
    if (typeof gtag === 'function') gtag('config', titik.id);
  }

  /* ---------- 4. Tahun otomatis di footer ------------------------------- */
  var y = document.getElementById('year');
  if (y) y.textContent = new Date().getFullYear();

  /* ---------- 5. Katalog menu: pencarian + filter kategori -------------- */
  var search  = document.getElementById('menuSearch');
  var filters = document.querySelectorAll('.filter');
  var groups  = document.querySelectorAll('.menu-group');
  var empty   = document.getElementById('noResult');

  if (groups.length) {
    var aktif = 'all';

    function terapkan() {
      var q = search ? search.value.trim().toLowerCase() : '';
      var totalTampil = 0;

      groups.forEach(function (group) {
        var cocokKategori = (aktif === 'all' || group.dataset.cat === aktif);
        var adaItem = 0;

        group.querySelectorAll('.menu-list li').forEach(function (li) {
          var cocokTeks = !q || li.textContent.toLowerCase().indexOf(q) !== -1;
          var tampil = cocokKategori && cocokTeks;
          li.classList.toggle('is-hidden', !tampil);
          if (tampil) adaItem++;
        });

        group.classList.toggle('is-hidden', adaItem === 0);
        totalTampil += adaItem;
      });

      if (empty) empty.classList.toggle('is-shown', totalTampil === 0);
    }

    filters.forEach(function (btn) {
      btn.addEventListener('click', function () {
        aktif = btn.dataset.filter;
        filters.forEach(function (b) {
          b.setAttribute('aria-pressed', String(b === btn));
        });
        terapkan();
      });
    });

    if (search) search.addEventListener('input', terapkan);
  }
})();
