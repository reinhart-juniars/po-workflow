{{--
    Bagian C: kotak peringatan menu yang dicentang Tampil di website tetapi
    tidak akan ikut terbit, per jenis masalah dengan saran perbaikannya.
    $warnings = WebsitePublisher::status()['warnings'] (lihat WebsiteMenuWarnings).
--}}
@if ($warnings)
    <section class="website-warning mt-4" role="alert" data-website-warnings>
        <div class="website-warning__head">
            @svg('heroicon-o-exclamation-triangle', 'h-5 w-5 shrink-0')
            <div>
                <p class="website-warning__title">
                    {{ array_sum(array_column($warnings, 'count')) }} menu dicentang Tampil di website, tetapi tidak akan ikut terbit
                </p>
                <p class="website-warning__meta">Menu ini tidak tampil di website sampai dibetulkan. Saran perbaikannya:</p>
            </div>
        </div>

        <ul class="website-warning__list">
            @foreach ($warnings as $type => $warning)
                <li data-warning-type="{{ $type }}">
                    <details>
                        <summary>
                            <b>{{ $warning['title'] }}</b> — {{ $warning['count'] }} menu
                        </summary>
                        <p class="website-warning__names">{{ implode(', ', $warning['names']) }}</p>
                    </details>
                    <p class="website-warning__hint">{{ $warning['hint'] }}</p>
                </li>
            @endforeach
        </ul>

        {{-- Katalog menyimpan tanpa memuat ulang halaman; isi kotak ini baru diperbarui setelah dimuat ulang. --}}
        <p class="website-warning__stale" data-website-warnings-stale hidden>
            Ada perubahan yang sudah tersimpan. <a href="{{ request()->fullUrl() }}" class="underline">Muat ulang halaman</a> untuk memperbarui peringatan ini.
        </p>

        <a href="{{ route('marketingapp.catalog.index', ['website' => 'tanpa-kategori', 'aktif' => 0]) }}" class="btn-ghost website-warning__action">
            Tampilkan menunya di Katalog
        </a>
    </section>

    @once
        @push('styles')
            <style>
                .website-warning { border: 1px solid #fcd34d; background: #fffbeb; border-radius: 12px; padding: 14px 16px; color: #78350f; }
                .website-warning__head { display: flex; gap: 10px; align-items: flex-start; color: #b45309; }
                .website-warning__title { font-size: 14px; font-weight: 600; color: #78350f; }
                .website-warning__meta { margin-top: 2px; font-size: 12.5px; color: #92400e; }
                .website-warning__list { margin: 10px 0 0 30px; display: grid; gap: 8px; font-size: 13px; }
                .website-warning__list summary { cursor: pointer; }
                .website-warning__names { margin-top: 4px; font-size: 12px; color: #92400e; overflow-wrap: anywhere; }
                .website-warning__hint { margin-top: 2px; font-size: 12.5px; color: #92400e; }
                .website-warning__action { margin: 12px 0 0 30px; }
                .website-warning__stale { margin: 10px 0 0 30px; font-size: 12.5px; font-weight: 600; color: #78350f; }
            </style>
        @endpush
    @endonce
@endif
