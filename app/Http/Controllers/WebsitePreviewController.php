<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\WebsiteMenuFeed;
use App\Services\WebsitePublisher;
use Illuminate\View\View;

/**
 * Bagian C: pratinjau menu website sebelum diterbitkan.
 *
 * Menampilkan isi yang AKAN terbit (data saat ini), ditandai bedanya dengan
 * terbitan terakhir, plus menu yang dicentang tetapi tidak akan ikut terbit
 * beserta alasannya. Tombol Terbitkan di halaman ini membawa sidik versi yang
 * sedang dilihat; bila menu berubah sebelum tombol ditekan, terbit ditolak.
 */
class WebsitePreviewController extends Controller
{
    public function show(WebsiteMenuFeed $feed, WebsitePublisher $publisher): View
    {
        $draft = $feed->build();
        $published = $publisher->publishedFeed();
        $changes = $publisher->changes($draft, $published);

        $ids = collect($draft['brands'])->flatMap(fn ($brand) => $brand['categories'])->flatMap(fn ($category) => $category['items'])->pluck('id');
        // Foto katalog (disk publik) untuk ditampilkan di browser; foto feed memakai URL bertoken.
        $photos = Product::query()->whereIn('id', $ids)->get(['id', 'photo_path'])->mapWithKeys(fn (Product $product) => [$product->id => $product->photoUrl()]);

        // Badge per menu: id => jenis perubahan.
        $marks = [];
        foreach (['added' => 'Baru', 'renamed' => 'Ganti nama', 'moved' => 'Pindah kategori', 'photo' => 'Foto baru'] as $type => $label) {
            foreach ($changes[$type] as $item) {
                $marks[$item['id']][] = $label;
            }
        }

        $status = $publisher->status();

        return view('marketingapp.website-preview', [
            'draft' => $draft,
            'published' => $published,
            'changes' => $changes,
            'changeCount' => array_sum(array_map('count', $changes)),
            'marks' => $marks,
            'photos' => $photos,
            'excluded' => $status['excluded'],
            'websiteStatus' => $status,
        ]);
    }
}
