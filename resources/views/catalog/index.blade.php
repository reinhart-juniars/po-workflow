@extends('layouts.marketingapp', ['wide' => true])

@section('title', 'Katalog Foto Menu')

@push('styles')
<style>
    .catalog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
    .catalog-card { display: flex; flex-direction: column; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden; }
    .catalog-photo { position: relative; aspect-ratio: 4 / 3; background: #f1f5f9; display: flex; align-items: center; justify-content: center; }
    .catalog-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .catalog-photo .empty { font-size: 11px; color: #94a3b8; text-align: center; padding: 0 10px; }
    .catalog-body { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 4px; flex: 1; }
    .catalog-name { font-size: 13px; font-weight: 600; color: #0f172a; line-height: 1.3; }
    .catalog-sku { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 10.5px; color: #475569; background: #f1f5f9; border-radius: 4px; padding: 1px 6px; display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .catalog-sku-edit summary { display: inline-flex; align-items: center; gap: 4px; max-width: 100%; cursor: pointer; list-style: none; }
    .catalog-sku-edit summary::-webkit-details-marker { display: none; }
    .catalog-sku-edit summary:hover .catalog-sku { background: #e2e8f0; }
    .catalog-sku-edit form { margin-top: 6px; display: flex; flex-direction: column; gap: 6px; }
    .catalog-sku-edit input { width: 100%; font-size: 12.5px; padding: 6px 8px; border-radius: 8px; }
    .catalog-sku-buttons { display: flex; gap: 6px; }
    .catalog-sku-buttons button { font-size: 12px; padding: 4px 10px; }
    .catalog-sku-error { font-size: 11.5px; color: #e11d48; }
    .catalog-sku-edit.is-busy form { opacity: .6; pointer-events: none; }
    .catalog-meta { font-size: 11.5px; color: #64748b; }
    .catalog-website { display: flex; align-items: center; gap: 8px; margin-top: 6px; padding: 6px 8px; border-radius: 8px; background: #f8fafc; font-size: 12px; font-weight: 600; color: #334155; cursor: pointer; user-select: none; }
    .catalog-website input { width: 16px; height: 16px; flex-shrink: 0; border-radius: 4px; }
    .catalog-website.is-on { background: #ecfdf5; color: #047857; }
    .catalog-website.is-disabled { cursor: not-allowed; color: #94a3b8; font-weight: 500; }
    .catalog-website.is-busy { opacity: .6; pointer-events: none; }
    .catalog-actions { display: flex; align-items: center; gap: 4px; margin-top: auto; padding-top: 8px; }
    .catalog-actions form { flex: 1; min-width: 0; }
    .catalog-badge { position: absolute; top: 8px; left: 8px; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 999px; background: rgba(15, 23, 42, 0.75); color: #fff; }
</style>
@endpush

@section('content')
    <div class="page-toolbar">
        <div>
            <h1 class="section-title">Katalog Foto Menu</h1>
            <p class="section-subtitle">
                Foto resmi tiap menu untuk materi promosi. Centang <b>Tampil di website</b> untuk menu yang terbit di
                website; SKU menjadi nama menunya di sana.
                {{ $summary['with_photo'] }} dari {{ $summary['total'] }} menu aktif sudah punya foto,
                <span data-website-count>{{ $summary['on_website'] }}</span> tampil di website.
            </p>
        </div>
    </div>

    @if (session('success'))
        <div class="flash-success mt-4">{{ session('success') }}</div>
    @endif
    @error('photo')
        <div class="flash-error mt-4">{{ $message }}</div>
    @enderror
    @error('show_on_website')
        <div class="flash-error mt-4">{{ $message }}</div>
    @enderror
    <div class="flash-error mt-4" data-website-error hidden></div>

    <x-table-toolbar standalone class="mt-4" title="Menu" :action="url()->current()"
        :meta="number_format($products->total(), 0, ',', '.').' menu'"
        search="q" :search-value="$q" search-placeholder="Cari nama atau SKU" live
        :filters="[
            ['type' => 'select', 'name' => 'foto', 'label' => 'Foto', 'placeholder' => 'Semua',
             'options' => ['ada' => 'Sudah ada foto', 'belum' => 'Belum ada foto'],
             'value' => $filter === 'semua' ? null : $filter],
            ['type' => 'select', 'name' => 'website', 'label' => 'Website', 'placeholder' => 'Semua',
             'options' => ['tampil' => 'Tampil di website', 'tidak' => 'Tidak tampil'],
             'value' => $website === 'semua' ? null : $website],
            ['type' => 'select', 'name' => 'aktif', 'label' => 'Status Menu', 'placeholder' => 'Hanya menu aktif',
             'options' => ['0' => 'Termasuk menu nonaktif'], 'value' => $activeOnly ? null : '0'],
        ]" />

    <div class="catalog-grid mt-4">
        @forelse ($products as $product)
            <div class="catalog-card">
                <div class="catalog-photo">
                    @if ($product->photoUrl())
                        <a href="{{ $product->photoUrl() }}" target="_blank" rel="noopener" title="Buka foto ukuran penuh">
                            <img src="{{ $product->photoUrl() }}" alt="Foto {{ $product->name }}" loading="lazy">
                        </a>
                    @else
                        <span class="empty">Belum ada foto</span>
                    @endif
                    @unless ($product->active)
                        <span class="catalog-badge">Nonaktif</span>
                    @endunless
                </div>
                <div class="catalog-body">
                    <div class="catalog-name">{{ $product->name }}</div>
                    {{-- SKU = nama menu di website; bisa diganti di sini atau di Admin › Master Menu. --}}
                    <details class="catalog-sku-edit" data-sku-edit>
                        <summary title="Ganti SKU (nama menu di website)">
                            <span class="catalog-sku" data-sku-text>{{ $product->sku ?: 'Isi SKU' }}</span>
                            @svg('heroicon-m-pencil-square', 'h-3.5 w-3.5 shrink-0 text-slate-400')
                        </summary>
                        <form method="POST" action="{{ route('marketingapp.catalog.sku', $product) }}" data-sku-form data-had-sku="{{ filled($product->sku) ? '1' : '0' }}">
                            @csrf
                            @method('PATCH')
                            <input type="text" name="sku" value="{{ $product->sku }}" required maxlength="100"
                                   aria-label="SKU {{ $product->name }}" placeholder="Nama menu di website">
                            <div class="catalog-sku-buttons">
                                <button type="submit" class="btn-primary">Simpan</button>
                                <button type="button" class="btn-ghost" data-sku-cancel>Batal</button>
                            </div>
                            <p class="catalog-sku-error" data-sku-error hidden></p>
                        </form>
                    </details>
                    <div class="catalog-meta">
                        Rp {{ number_format((float) $product->base_price, 0, ',', '.') }} / {{ $product->unit }}
                        @if ($product->photo_updated_at)
                            · foto {{ $product->photo_updated_at->format('d/m/Y') }}
                        @endif
                    </div>

                    {{-- Centang langsung tersimpan (skrip di bawah); tanpa JS, tombol Simpan mengirim form biasa. --}}
                    <form method="POST" action="{{ route('marketingapp.catalog.website', $product) }}" data-website-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="show_on_website" value="0">
                        @if (filled($product->sku))
                            <label class="catalog-website {{ $product->show_on_website ? 'is-on' : '' }}">
                                <input type="checkbox" name="show_on_website" value="1" @checked($product->show_on_website) data-website-toggle>
                                Tampil di website
                            </label>
                            <noscript><button type="submit" class="btn-ghost mt-1 text-xs">Simpan</button></noscript>
                        @else
                            <label class="catalog-website is-disabled" title="SKU = nama menu di website. Isi SKU di Admin › Master Menu.">
                                <input type="checkbox" disabled>
                                Isi SKU dulu untuk tampil di website
                            </label>
                        @endif
                    </form>

                    <div class="catalog-actions">
                        {{-- Satu tombol: memilih berkas langsung mengunggah (skrip di bawah). --}}
                        <form method="POST" action="{{ route('marketingapp.catalog.upload', $product) }}" enctype="multipart/form-data">
                            @csrf
                            <label class="catalog-upload">
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="sr-only" required data-catalog-upload>
                                @svg('heroicon-m-arrow-up-tray', 'h-4 w-4 shrink-0')
                                <span data-catalog-upload-label>{{ $product->photo_path ? 'Ganti foto' : 'Unggah foto' }}</span>
                            </label>
                        </form>
                        @if ($product->photo_path)
                            <x-row-action kind="delete" label="Hapus foto" :action="route('marketingapp.catalog.photo.destroy', $product)"
                                          :confirm="'Hapus foto '.$product->name.'?'" />
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="app-card p-8 text-center text-sm text-slate-500" style="grid-column: 1 / -1;">Tidak ada menu yang cocok.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $products->links() }}</div>

    <script>
        document.addEventListener('change', (event) => {
            const input = event.target.closest('[data-catalog-upload]');
            if (!input || !input.files.length) return;
            const label = input.closest('.catalog-upload');
            label.classList.add('is-busy');
            label.querySelector('[data-catalog-upload-label]').textContent = 'Mengunggah…';
            input.form.requestSubmit();
        });

        // Ganti SKU di kartu: tersimpan tanpa memuat ulang halaman. Menu yang baru
        // pertama kali diberi SKU dimuat ulang supaya centang website-nya aktif.
        document.addEventListener('submit', async (event) => {
            const form = event.target.closest('[data-sku-form]');
            if (!form) return;
            event.preventDefault();
            const details = form.closest('[data-sku-edit]');
            const errorBox = form.querySelector('[data-sku-error]');
            details.classList.add('is-busy');
            errorBox.hidden = true;

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const body = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(body.errors?.sku?.[0] || body.message || 'Gagal menyimpan. Muat ulang halaman lalu coba lagi.');
                }
                if (form.dataset.hadSku === '0') {
                    window.location.reload();
                    return;
                }
                details.querySelector('[data-sku-text]').textContent = body.sku;
                const input = form.querySelector('input[name=sku]');
                input.value = input.defaultValue = body.sku;
                details.open = false;
            } catch (error) {
                errorBox.textContent = error.message;
                errorBox.hidden = false;
            } finally {
                details.classList.remove('is-busy');
            }
        });

        document.addEventListener('click', (event) => {
            const cancel = event.target.closest('[data-sku-cancel]');
            if (!cancel) return;
            const details = cancel.closest('[data-sku-edit]');
            const input = details.querySelector('input[name=sku]');
            input.value = input.defaultValue;
            details.querySelector('[data-sku-error]').hidden = true;
            details.open = false;
        });

        document.addEventListener('toggle', (event) => {
            if (event.target.matches?.('[data-sku-edit]') && event.target.open) {
                event.target.querySelector('input[name=sku]').focus();
            }
        }, true);

        // Centang "Tampil di website" tersimpan tanpa memuat ulang halaman, supaya
        // marketing bisa mencentang banyak menu berturut-turut tanpa kehilangan posisi.
        document.addEventListener('change', async (event) => {
            const toggle = event.target.closest('[data-website-toggle]');
            if (!toggle) return;
            const label = toggle.closest('.catalog-website');
            const errorBox = document.querySelector('[data-website-error]');
            label.classList.add('is-busy');
            errorBox.hidden = true;

            try {
                const response = await fetch(toggle.form.action, {
                    method: 'POST',
                    body: new FormData(toggle.form),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const body = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(body.message || 'Gagal menyimpan. Muat ulang halaman lalu coba lagi.');

                toggle.checked = body.show_on_website;
                label.classList.toggle('is-on', body.show_on_website);
                document.querySelectorAll('[data-website-count]').forEach((el) => { el.textContent = body.on_website; });
            } catch (error) {
                toggle.checked = !toggle.checked;
                errorBox.textContent = error.message;
                errorBox.hidden = false;
            } finally {
                label.classList.remove('is-busy');
            }
        });
    </script>
@endsection
