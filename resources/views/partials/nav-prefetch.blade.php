{{-- Prefetch halaman menu sidebar saat kursor diarahkan ke sana (Speculation
     Rules, Chrome/Edge; browser lain mengabaikannya). Saat diklik, HTML-nya
     sudah ada, jadi waktu tunggu server hilang dari perpindahan menu.

     Sengaja "prefetch", bukan "prerender": Laravel mengenali header
     Sec-Purpose: prefetch dan tidak menyimpannya sebagai URL sebelumnya
     (redirect()->back() setelah validasi gagal tetap ke halaman yang benar),
     sedangkan header prerender ("prefetch;prerender") tidak dikenali. --}}
<script type="speculationrules">
  {
    "prefetch": [{
      "where": {
        "and": [
          { "selector_matches": ".fi-sidebar-item-button, .shell-item" },
          { "not": { "selector_matches": "[aria-current=page], .fi-active > .fi-sidebar-item-button" } }
        ]
      },
      "eagerness": "moderate"
    }]
  }
</script>
