<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\WebsiteMenuFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Bagian C: endpoint yang dibaca skrip build www.w3scatering.com saat
 * marketing menekan "Terbitkan ke Website". Dilindungi EnsureWebsiteToken.
 */
class WebsiteMenuController extends Controller
{
    public function index(WebsiteMenuFeed $feed): JsonResponse
    {
        return response()->json(['generated_at' => now()->toIso8601String()] + $feed->build());
    }

    /** Foto 560x420 WebP; hanya untuk menu yang memang terbit dan berfoto. */
    public function photo(int $product, WebsiteMenuFeed $feed): Response
    {
        // Menu yang tidak terbit dijawab 404 yang sama dengan id yang tidak ada,
        // supaya endpoint ini tidak membocorkan foto menu internal.
        $menu = Product::query()->onWebsite()->whereNotNull('photo_path')->find($product);
        abort_if($menu === null, 404);

        return response($feed->photo($menu), 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
