<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\WebsiteMenuFeed;
use App\Services\WebsitePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Bagian C: endpoint yang dibaca skrip build www.w3scatering.com. Dilindungi
 * EnsureWebsiteToken.
 *
 * Yang disajikan adalah snapshot terbitan terakhir (setelah marketing melihat
 * pratinjau dan menekan Terbitkan), bukan data yang sedang disunting. Belum
 * pernah terbit = 404, dan skrip build mempertahankan menu website yang ada.
 */
class WebsiteMenuController extends Controller
{
    public function index(WebsitePublisher $publisher): JsonResponse
    {
        $feed = $publisher->publishedFeed();

        if ($feed === null) {
            return response()->json(['message' => 'Menu belum pernah diterbitkan dari 3S ONE.'], 404);
        }

        return response()->json(['generated_at' => now()->toIso8601String()] + $feed);
    }

    /**
     * Foto 560x420 WebP; hanya untuk menu yang ada di terbitan terakhir dan
     * masih berfoto.
     */
    public function photo(int $product, WebsiteMenuFeed $feed, WebsitePublisher $publisher): Response
    {
        $published = collect($publisher->publishedFeed()['brands'] ?? [])
            ->flatMap(fn (array $brand) => $brand['categories'])
            ->flatMap(fn (array $category) => $category['items'])
            ->pluck('id');

        // Menu yang tidak terbit dijawab 404 yang sama dengan id yang tidak ada,
        // supaya endpoint ini tidak membocorkan foto menu internal.
        $menu = $published->contains($product) ? Product::query()->whereNotNull('photo_path')->find($product) : null;
        abort_if($menu === null, 404);

        return response($feed->photo($menu), 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
