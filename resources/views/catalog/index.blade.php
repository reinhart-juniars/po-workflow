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
    .catalog-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: auto; padding-top: 6px; }
    .catalog-actions .file-input { font-size: 11px; max-width: 100%; }
    .catalog-actions .btn-xs { font-size: 11px; padding: 4px 8px; }
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

    <form method="GET" class="app-card mt-4 flex flex-wrap items-end gap-3 p-4">
        <label class="flex-1 min-w-[200px]">
            <span class="form-label">Cari nama atau SKU</span>
            <input type="search" name="q" value="{{ $q }}" class="form-control" placeholder="mis. nasi kuning atau kode SKU">
        </label>
        <label>
            <span class="form-label">Foto</span>
            <select name="foto" class="form-control">
                <option value="semua" @selected($filter === 'semua')>Semua</option>
                <option value="ada" @selected($filter === 'ada')>Sudah ada foto</option>
                <option value="belum" @selected($filter === 'belum')>Belum ada foto</option>
            </select>
        </label>
        <label class="flex items-center gap-2 pb-2 text-sm text-slate-700">
            <input type="hidden" name="aktif" value="0">
            <input type="checkbox" name="aktif" value="1" @checked($activeOnly)> Hanya menu aktif
        </label>
        <button type="submit" class="btn-primary">Terapkan</button>
    </form>

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
                            <form method="POST" action="{{ route('adminapp.catalog.upload', $product) }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-1">
                                @csrf
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="file-input" required>
                                <button type="submit" class="btn-primary btn-xs">{{ $product->photo_path ? 'Ganti' : 'Unggah' }}</button>
                            </form>
                            @if ($product->photo_path)
                                <form method="POST" action="{{ route('adminapp.catalog.photo.destroy', $product) }}" onsubmit="return confirm('Hapus foto {{ addslashes($product->name) }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-ghost btn-xs">Hapus</button>
                                </form>
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
@endsection
