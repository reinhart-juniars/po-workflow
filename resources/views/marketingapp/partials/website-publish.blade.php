{{--
    Bagian C: status website + tombol "Terbitkan ke Website".
    $websiteStatus = WebsitePublisher::status(); $onWebsite = jumlah menu yang terbit.
--}}
<section class="app-card website-publish mt-4" data-website-publish>
    <div class="website-publish__text">
        <p class="website-publish__title">
            Website
            @if (! $websiteStatus['configured'])
                <span class="website-publish__badge is-muted">Belum tersambung</span>
            @elseif ($websiteStatus['pending'])
                <span class="website-publish__badge is-pending">Ada perubahan belum diterbitkan</span>
            @else
                <span class="website-publish__badge is-ok">Sudah terbaru</span>
            @endif
        </p>
        <p class="website-publish__meta">
            {{ number_format($onWebsite, 0, ',', '.') }} menu akan tampil di website.
            @if ($websiteStatus['last'])
                Terakhir diterbitkan {{ $websiteStatus['last']->created_at->translatedFormat('d M Y, H:i') }}
                oleh {{ $websiteStatus['last']->user?->name ?? 'pengguna terhapus' }}.
            @else
                Belum pernah diterbitkan dari 3S ONE.
            @endif
            <a href="{{ $websiteStatus['url'] }}" target="_blank" rel="noopener" class="underline">Lihat website</a>
        </p>
    </div>
    {{-- Terbit selalu lewat pratinjau: menu dicek dulu sebelum tampil di website. --}}
    <a href="{{ route('marketingapp.website.preview') }}" class="btn-primary">
        @svg('heroicon-m-eye', 'h-4 w-4')
        Pratinjau &amp; Terbitkan
    </a>
</section>

@include('marketingapp.partials.website-warnings', ['warnings' => $websiteStatus['warnings']])

@once
    @push('styles')
        <style>
            .website-publish { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; }
            .website-publish__text { min-width: 0; flex: 1 1 320px; }
            .website-publish__title { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: #0f172a; }
            .website-publish__meta { margin-top: 4px; font-size: 12.5px; color: #64748b; }
            .website-publish__badge { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; }
            .website-publish__badge.is-ok { background: #ecfdf5; color: #047857; }
            .website-publish__badge.is-pending { background: #fffbeb; color: #b45309; }
            .website-publish__badge.is-muted { background: #f1f5f9; color: #64748b; }
            .website-publish button[disabled] { opacity: .55; cursor: not-allowed; }
        </style>
    @endpush
@endonce
