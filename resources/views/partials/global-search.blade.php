{{--
  Pencarian global 3S ONE. Dipakai header Blade (layouts.shell) DAN topbar
  Filament lewat render hook, jadi hanya memakai kelas dari
  resources/css/shell.css.

  Tempatnya di ATAS SIDEBAR, bukan di bilah atas. Bilah atas sudah memuat
  bilah aplikasi (sampai 8 tab untuk superadmin/owner), lonceng, dan menu
  pengguna -- ruangnya pas-pasan. Menaruh apa pun di sana, bahkan tombol ikon
  38px, membuat tab terakhir ("Delivery") terpotong. Sidebar punya ruang
  kosong di atas nama aplikasi, dan tombol berlabel di sana justru lebih
  mudah ditemukan daripada ikon telanjang.

  Panelnya sendiri terbuka sebagai overlay di tengah layar (pola command
  palette), jadi hasilnya dapat ruang 640px -- tidak terikat lebar sidebar.

  Tanpa JavaScript tombolnya tetap sebuah tautan biasa ke halaman /search.
  JavaScript hanya meningkatkannya menjadi overlay -- halaman shell tidak
  memuat Alpine (lihat layouts.shell), jadi ditulis polos seperti
  partials/user-menu.blade.php.
--}}
@auth
<div class="sh-search"
     data-sh-search
     data-sh-search-url="{{ route('search.suggest') }}"
     data-sh-search-min="{{ \App\Services\GlobalSearchService::MIN_LENGTH }}">

  <a href="{{ route('search.index') }}" class="sh-search-trigger" data-sh-search-open
     title="Cari dokumen (Ctrl K)">
    @svg('heroicon-o-magnifying-glass', 'sh-search-trigger-icon')
    <span class="sh-search-trigger-label">Cari dokumen…</span>
    <kbd class="sh-search-kbd" aria-hidden="true">Ctrl K</kbd>
  </a>

  <dialog class="sh-search-dialog" data-sh-search-dialog aria-label="Pencarian global">
    <form method="GET" action="{{ route('search.index') }}" role="search" autocomplete="off">
      <label class="sh-search-field">
        <span class="sr-only">Cari dokumen</span>
        @svg('heroicon-o-magnifying-glass', 'sh-search-icon')
        <input
          type="search"
          name="q"
          placeholder="Cari PO, DO, pelanggan, menu, bahan…"
          maxlength="100"
          role="combobox"
          aria-expanded="false"
          aria-controls="sh-search-results"
          aria-autocomplete="list"
          data-sh-search-input>
        <kbd class="sh-search-kbd" aria-hidden="true">Esc</kbd>
      </label>

      <div class="sh-search-panel" id="sh-search-results" role="listbox" data-sh-search-panel>
        <p class="sh-search-hint">
          Ketik minimal {{ \App\Services\GlobalSearchService::MIN_LENGTH }} huruf.
          Tekan Enter untuk membuka halaman hasil lengkap.
        </p>
      </div>
    </form>
  </dialog>
</div>

<script>
  // Satu pengikat untuk seluruh halaman.
  if (!window.__shSearchBound) {
    window.__shSearchBound = true;

    const JEDA_KETIK = 180; // ms -- cukup untuk tidak menembak tiap huruf

    document.querySelectorAll('[data-sh-search]').forEach((akar) => {
      const dialog = akar.querySelector('[data-sh-search-dialog]');
      const pemicu = akar.querySelector('[data-sh-search-open]');
      const input = akar.querySelector('[data-sh-search-input]');
      const panel = akar.querySelector('[data-sh-search-panel]');
      const sambutan = panel.innerHTML;

      // <dialog> tidak didukung: biarkan tombolnya jadi tautan biasa.
      if (typeof dialog.showModal !== 'function') return;

      // Dipindah ke <body>: di tempat asalnya dialog ini bersarang di dalam
      // header yang ber-backdrop-filter (dan di panel Filament di dalam
      // topbar-nya). Overlay tidak boleh bergantung pada konteks penumpukan
      // induknya -- ini yang kemarin membuat panel menimpa konten.
      if (dialog.parentElement !== document.body) document.body.append(dialog);

      const URL_SUGGEST = akar.dataset.shSearchUrl;
      const MIN = Number(akar.dataset.shSearchMin || 2);
      let timer = null;
      let sorot = -1;

      const buka = () => {
        if (dialog.open) return;
        dialog.showModal();
        input.focus();
        input.select();
      };

      pemicu.addEventListener('click', (e) => { e.preventDefault(); buka(); });

      // Klik latar menutup (dialog sendiri hanya menangani Escape).
      dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
      dialog.addEventListener('close', () => {
        panel.innerHTML = sambutan;
        input.value = '';
        input.setAttribute('aria-expanded', 'false');
        sorot = -1;
      });

      const baris = () => panel.querySelectorAll('.sh-search-item');

      const geser = (arah) => {
        const daftar = baris();
        if (!daftar.length) return;
        sorot = (sorot + arah + daftar.length) % daftar.length;
        daftar.forEach((el, i) => el.classList.toggle('is-active', i === sorot));
        daftar[sorot].scrollIntoView({ block: 'nearest' });
      };

      // Hasil dirakit dengan textContent, bukan innerHTML: nama pelanggan dan
      // catatan dokumen adalah teks buatan pengguna.
      const gambar = (hasil, kueri) => {
        panel.replaceChildren();
        input.setAttribute('aria-expanded', hasil.length ? 'true' : 'false');

        if (!hasil.length) {
          const kosong = document.createElement('p');
          kosong.className = 'sh-search-empty';
          kosong.textContent = 'Tidak ada yang cocok dengan "' + kueri + '".';
          panel.append(kosong);
          return;
        }

        let grupTerakhir = null;

        hasil.forEach((item) => {
          if (item.group !== grupTerakhir) {
            grupTerakhir = item.group;
            const judul = document.createElement('p');
            judul.className = 'sh-search-group';
            judul.textContent = item.group;
            panel.append(judul);
          }

          const tautan = document.createElement('a');
          tautan.className = 'sh-search-item';
          tautan.href = item.url;
          tautan.setAttribute('role', 'option');

          const teks = document.createElement('span');
          teks.className = 'sh-search-text';

          const judulBaris = document.createElement('span');
          judulBaris.className = 'sh-search-title';
          judulBaris.textContent = item.title;
          teks.append(judulBaris);

          if (item.meta) {
            const meta = document.createElement('span');
            meta.className = 'sh-search-meta';
            meta.textContent = item.meta;
            teks.append(meta);
          }

          tautan.append(teks);
          panel.append(tautan);
        });

        sorot = -1;
      };

      const cari = () => {
        const kueri = input.value.trim();

        if (kueri.length < MIN) {
          panel.innerHTML = sambutan;
          input.setAttribute('aria-expanded', 'false');
          sorot = -1;
          return;
        }

        fetch(URL_SUGGEST + '?q=' + encodeURIComponent(kueri), {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        })
          .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
          // Balapan ketikan: buang jawaban yang sudah tidak relevan.
          .then((data) => { if (data.query === input.value.trim()) gambar(data.results, data.query); })
          .catch(() => { panel.replaceChildren(); });
      };

      input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(cari, JEDA_KETIK);
      });

      input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); geser(1); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); geser(-1); return; }
        if (e.key === 'Enter' && sorot > -1) {
          // Enter tanpa memilih baris tetap mengirim form ke halaman hasil.
          e.preventDefault();
          baris()[sorot].click();
        }
      });
    });

    // Ctrl/Cmd + K membuka pencarian yang ada di halaman ini.
    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        const pemicu = [...document.querySelectorAll('[data-sh-search-open]')]
          .find((el) => el.offsetParent !== null);

        if (pemicu) { e.preventDefault(); pemicu.click(); }
      }
    });
  }
</script>
@endauth
