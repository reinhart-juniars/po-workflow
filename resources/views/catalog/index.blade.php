@extends($layout, ['wide' => true])

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
    .catalog-meta { font-size: 11.5px; color: #64748b; }
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
                Sumber foto resmi tiap menu berdasarkan SKU untuk materi promosi.
                {{ $summary['with_photo'] }} dari {{ $summary['total'] }} menu aktif sudah punya foto.
                @unless ($canManage) Unggah dan ganti foto dilakukan dari aplikasi Admin. @endunless
            </p>
        </div>
    </div>

    @if (session('success'))
        <div class="flash-success mt-4">{{ session('success') }}</div>
    @endif
    @error('photo')
        <div class="flash-error mt-4">{{ $message }}</div>
    @enderror

    <x-table-toolbar standalone class="mt-4" title="Menu" :action="url()->current()"
        :meta="number_format($products->total(), 0, ',', '.').' menu'"
        search="q" :search-value="$q" search-placeholder="Cari nama atau SKU" live
        :filters="[
            ['type' => 'select', 'name' => 'foto', 'label' => 'Foto', 'placeholder' => 'Semua',
             'options' => ['ada' => 'Sudah ada foto', 'belum' => 'Belum ada foto'],
             'value' => $filter === 'semua' ? null : $filter],
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
                    <span class="catalog-sku" title="{{ $product->sku }}">{{ $product->sku ?: '-' }}</span>
                    <div class="catalog-meta">
                        Rp {{ number_format((float) $product->base_price, 0, ',', '.') }} / {{ $product->unit }}
                        @if ($product->photo_updated_at)
                            · foto {{ $product->photo_updated_at->format('d/m/Y') }}
                        @endif
                    </div>
                    @if ($canManage)
                        <div class="catalog-actions">
                            {{-- Satu tombol: memilih berkas langsung mengunggah (skrip di bawah). --}}
                            <form method="POST" action="{{ route('adminapp.catalog.upload', $product) }}" enctype="multipart/form-data">
                                @csrf
                                <label class="catalog-upload">
                                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="sr-only" required data-catalog-upload>
                                    @svg('heroicon-m-arrow-up-tray', 'h-4 w-4 shrink-0')
                                    <span data-catalog-upload-label>{{ $product->photo_path ? 'Ganti foto' : 'Unggah foto' }}</span>
                                </label>
                            </form>
                            @if ($product->photo_path)
                                <x-row-action kind="delete" label="Hapus foto" :action="route('adminapp.catalog.photo.destroy', $product)"
                                              :confirm="'Hapus foto '.$product->name.'?'" />
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="app-card p-8 text-center text-sm text-slate-500" style="grid-column: 1 / -1;">Tidak ada menu yang cocok.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $products->links() }}</div>

    @if ($canManage)
        <script>
            document.addEventListener('change', (event) => {
                const input = event.target.closest('[data-catalog-upload]');
                if (!input || !input.files.length) return;
                const label = input.closest('.catalog-upload');
                label.classList.add('is-busy');
                label.querySelector('[data-catalog-upload-label]').textContent = 'Mengunggah…';
                input.form.requestSubmit();
            });
        </script>
    @endif
@endsection
