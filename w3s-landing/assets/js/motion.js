/* ==========================================================================
   W3S Catering — Gerak & kedalaman
   --------------------------------------------------------------------------
   Semua efek bersifat progressive enhancement: kalau berkas ini gagal dimuat,
   skrip penjaga di <head> melepas kelas .js dan halaman tetap terbaca penuh.
   Seluruhnya mati saat pengguna mengaktifkan "kurangi gerak".

   Catatan v2: shader bara api WebGL dihapus. Efek itu dibuat untuk kanvas
   hitam netral dan tidak lagi relevan — sekarang kedalaman datang dari
   fotonya sendiri (parallax + tilt), bukan dari hiasan latar.
   ========================================================================== */
(function () {
  'use strict';

  window.__w3sMotion = true;

  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var punyaMouse = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  /* ======================================================================
     1. Judul hero terbuka baris demi baris
     ====================================================================== */
  function initHeroLines() {
    var lines = document.querySelectorAll('#heroTitle .reveal-line');
    Array.prototype.forEach.call(lines, function (line, i) {
      line.style.setProperty('--d', (reduce ? 0 : 120 + i * 130) + 'ms');
      line.classList.add('is-in');
    });
  }

  /* ======================================================================
     2. Parallax hero — foto bergerak lebih lambat dari scroll.

        Angkanya sengaja kecil. Setiap parallax menuntut foto dibesarkan dulu,
        dan setiap pembesaran memakan tepi foto. Dengan scale 1.10 hanya 5%
        di tiap sisi yang jadi ruang gerak — itu bagian meja kayu, bukan
        tampahnya. Jangan naikkan angka ini: di atas ~1.14 daun pisang mulai
        terpotong dan keluhan "hero kepotong" kembali.
     ====================================================================== */
  function initParallax() {
    var media = document.querySelector('.hero__shot img');
    if (!media || reduce) return;

    media.style.transform = 'scale(1.10)';
    media.style.transformOrigin = 'center center';

    var hero = document.querySelector('.hero');
    var tick = false, terlihat = true;

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (e) { terlihat = e[0].isIntersecting; })
        .observe(hero);
    }

    function update() {
      tick = false;
      if (!terlihat) return;
      var y = window.scrollY;
      var h = hero.offsetHeight || 1;
      var p = Math.min(y / h, 1);
      media.style.transform = 'scale(1.10) translate3d(0,' + (p * 4.5).toFixed(2) + '%,0)';
    }
    window.addEventListener('scroll', function () {
      if (!tick) { tick = true; requestAnimationFrame(update); }
    }, { passive: true });
    update();
  }

  /* ======================================================================
     3. Tilt 3D pada tile makanan — hanya perangkat bermouse.
        Sudutnya sengaja kecil (4 derajat): cukup terasa hidup, tidak norak.
     ====================================================================== */
  function initTilt() {
    if (reduce || !punyaMouse) return;

    document.querySelectorAll('[data-tilt]').forEach(function (el) {
      var raf = null;
      el.style.transformStyle = 'preserve-3d';

      el.addEventListener('pointermove', function (e) {
        if (raf) return;
        raf = requestAnimationFrame(function () {
          raf = null;
          var r = el.getBoundingClientRect();
          var px = (e.clientX - r.left) / r.width;
          var py = (e.clientY - r.top) / r.height;
          el.style.transition = 'transform .1s linear';
          el.style.transform =
            'perspective(1200px) rotateX(' + ((0.5 - py) * 4).toFixed(2) + 'deg)' +
            ' rotateY(' + ((px - 0.5) * 4).toFixed(2) + 'deg)';
        });
      });

      el.addEventListener('pointerleave', function () {
        el.style.transition = 'transform .55s cubic-bezier(.22,.61,.36,1)';
        el.style.transform = '';
      });
    });
  }

  /* ======================================================================
     4. Reveal saat masuk layar, bertahap antar elemen bersaudara
     ====================================================================== */
  function initReveal() {
    var items = document.querySelectorAll('[data-reveal]');
    if (!items.length) return;

    if (reduce || !('IntersectionObserver' in window)) {
      items.forEach(function (el) { el.classList.add('is-in'); });
      return;
    }

    /* Jeda bertahap dipendekkan di layar sempit. Di sana semua grid runtuh jadi
       satu kolom, jadi jumlah elemen bersaudara per layar jauh lebih banyak;
       dengan 80ms, elemen ke-8 baru muncul 640ms setelah yang pertama dan
       terasa tertinggal jauh di belakang jempol. Total jeda juga dibatasi
       supaya daftar panjang tidak pernah menunggu lebih dari ~0,4 detik. */
    var sempit = window.matchMedia('(max-width: 760px)').matches;
    var langkah = sempit ? 45 : 80;
    var maksimal = sempit ? 360 : 640;

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        var grup = el.parentElement
          ? [].slice.call(el.parentElement.querySelectorAll('[data-reveal]')) : [];
        var i = grup.indexOf(el);
        var jeda = i > 0 ? Math.min(i * langkah, maksimal) : 0;
        el.style.setProperty('--d', jeda + 'ms');
        el.classList.add('is-in');
        io.unobserve(el);
      });
    }, { threshold: 0.1, rootMargin: '0px 0px -5% 0px' });

    items.forEach(function (el) { io.observe(el); });
  }

  /* ======================================================================
     5. Angka menghitung naik
     ====================================================================== */
  function initCount() {
    var nums = document.querySelectorAll('[data-count]');
    if (!nums.length || reduce || !('IntersectionObserver' in window)) return;

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        io.unobserve(el);
        var target = parseFloat(el.getAttribute('data-count'));
        var suffix = el.getAttribute('data-suffix') || '';
        var t0 = performance.now(), dur = 1200;
        (function tick(now) {
          var p = Math.min((now - t0) / dur, 1);
          var e = 1 - Math.pow(1 - p, 3);
          el.textContent = Math.round(target * e).toLocaleString('id-ID') + suffix;
          if (p < 1) requestAnimationFrame(tick);
        })(t0);
      });
    }, { threshold: 0.5 });

    nums.forEach(function (el) { io.observe(el); });
  }

  /* ======================================================================
     6. Header memunculkan garis setelah di-scroll
     ====================================================================== */
  function initHeader() {
    var header = document.querySelector('.site-header');
    if (!header) return;
    var tick = false;
    function update() { tick = false; header.classList.toggle('is-stuck', window.scrollY > 12); }
    window.addEventListener('scroll', function () {
      if (!tick) { tick = true; requestAnimationFrame(update); }
    }, { passive: true });
    update();
  }

  /* ======================================================================
     7. Tumpukan kartu hero (index.html)

        Dua hal terjadi di sini, dan keduanya HANYA menyentuh --i dan dua
        variabel sudut. Seluruh geometri kipasnya milik CSS — kalau tumpukannya
        perlu diatur ulang, atur di style.css, bukan di sini.

        a. Miring mengikuti kursor. .decks memegang perspective dan .deck
           memegang preserve-3d, jadi memiringkan .deck membuat kartu di
           translateZ berbeda bergeser dengan laju berbeda. Itu parallax asli;
           tidak ada lapisan yang digeser manual.

        b. Kartu depan mundur ke belakang. Cukup mengurangi --i tiap kartu
           secara melingkar — transisi CSS yang mengurus gerakannya.
     ====================================================================== */
  function initDecks() {
    var wadah = document.querySelector('.decks');
    if (!wadah) return;

    var decks = [].slice.call(wadah.querySelectorAll('[data-deck]'));
    if (!decks.length) return;

    /* --- b. pergantian kartu --- */
    function majukan(deck) {
      var kartu = [].slice.call(deck.querySelectorAll('.deck__card'));
      var n = kartu.length;
      kartu.forEach(function (k) {
        var i = parseInt(k.style.getPropertyValue('--i'), 10) || 0;
        /* -1 melingkar: yang di depan (0) melompat ke paling belakang (n-1),
           sisanya maju satu tingkat. */
        k.style.setProperty('--i', (i - 1 + n) % n);
      });
    }

    /* Ditekan = maju. Ini satu-satunya cara maju di layar sentuh, dan di
       desktop ia memberi kendali kepada orang yang tidak mau menunggu. */
    decks.forEach(function (deck) {
      deck.addEventListener('click', function () { majukan(deck); });
    });

    if (reduce) return;   /* diam total: tanpa tilt, tanpa pergantian otomatis */

    /* Berjalan sendiri, tapi berhenti begitu hero keluar layar — tidak ada
       gunanya menganimasikan sesuatu yang tidak dilihat siapa pun. Kedua
       tumpukan sengaja tidak seirama; kalau berbalik bersamaan gerakannya
       terbaca mekanis. */
    var terlihat = true;
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (e) { terlihat = e[0].isIntersecting; })
        .observe(wadah);
    }
    decks.forEach(function (deck, k) {
      setTimeout(function () {
        setInterval(function () { if (terlihat) majukan(deck); }, 5200);
      }, k * 2600);
    });

    /* --- a. tilt --- */
    if (!punyaMouse) return;

    var raf = null;
    wadah.addEventListener('pointermove', function (e) {
      if (raf) return;
      raf = requestAnimationFrame(function () {
        raf = null;
        var r = wadah.getBoundingClientRect();
        var px = (e.clientX - r.left) / r.width;
        var py = (e.clientY - r.top) / r.height;
        /* 7 derajat: cukup untuk memunculkan kedalaman, masih jauh dari
           tepi di mana kartu belakang mulai terlihat pipih dan palsu. */
        var rx = ((0.5 - py) * 7).toFixed(2);
        var ry = ((px - 0.5) * 7).toFixed(2);
        decks.forEach(function (deck) {
          deck.style.transition = 'transform .12s linear';
          deck.style.transform = 'rotateX(' + rx + 'deg) rotateY(' + ry + 'deg)';
        });
      });
    });

    wadah.addEventListener('pointerleave', function () {
      decks.forEach(function (deck) {
        deck.style.transition = 'transform .6s cubic-bezier(.22,.61,.36,1)';
        deck.style.transform = '';
      });
    });
  }

  function boot() {
    initHeader();
    initHeroLines();
    initReveal();
    initCount();
    initTilt();
    initParallax();
    initDecks();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else { boot(); }
})();
