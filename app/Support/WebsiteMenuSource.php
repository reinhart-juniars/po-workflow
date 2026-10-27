<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Daftar menu yang SEKARANG tampil di website lama (w3s-landing), dibaca dari
 * HTML-nya: wuenak-poll.html dan 3s.html.
 *
 * Dipakai sekali jalan untuk Bagian C: memetakan tiap hidangan di website ke
 * satu produk Master Menu, supaya sesudahnya website dibangun dari 3S ONE dan
 * tidak diketik manual lagi. Struktur halamannya:
 *
 *     <div class="menu-group" data-cat="ayam">
 *       <h2>Menu Ayam</h2>
 *       <ul><li><img src="assets/menu/x.webp"><span class="mi__n">Nama</span></li>
 *           <li>Nama tanpa foto</li></ul>
 */
final class WebsiteMenuSource
{
    /** Halaman website => brand. */
    public const PAGES = [
        'wuenak-poll.html' => WebsiteCatalog::BRAND_WWP,
        '3s.html' => WebsiteCatalog::BRAND_3S,
    ];

    /**
     * @return list<array{page: string, brand: string, group: string, category: ?string, name: string, photo: ?string, order: int}>
     */
    public static function read(string $siteDir): array
    {
        $dishes = [];

        foreach (self::PAGES as $page => $brand) {
            $path = rtrim($siteDir, '/\\').DIRECTORY_SEPARATOR.$page;

            if (! is_file($path)) {
                throw new RuntimeException("Halaman website tidak ditemukan: {$path}");
            }

            $dom = new DOMDocument;
            // HTML5 tidak dikenal libxml; peringatan tag baru (section, ...) diabaikan.
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">'.file_get_contents($path));
            libxml_clear_errors();

            $xpath = new DOMXPath($dom);

            foreach ($xpath->query('//div[contains(concat(" ", @class, " "), " menu-group ") and @data-cat]') as $group) {
                /** @var DOMElement $group */
                $title = trim((string) $xpath->evaluate('string(.//h2)', $group));

                foreach ($xpath->query('.//ul/li', $group) as $item) {
                    /** @var DOMElement $item */
                    $label = $xpath->query('.//span[contains(@class, "mi__n")]', $item)->item(0);
                    $name = self::clean($label ? $label->textContent : $item->textContent);

                    if ($name === '') {
                        continue;
                    }

                    $img = $xpath->query('.//img', $item)->item(0);

                    $dishes[] = [
                        'page' => $page,
                        'brand' => $brand,
                        'group' => self::clean($title),
                        'category' => self::categoryFor($brand, $title),
                        'name' => $name,
                        'photo' => $img instanceof DOMElement ? basename($img->getAttribute('src')) : null,
                        'order' => count($dishes) + 1,
                    ];
                }
            }
        }

        return $dishes;
    }

    /** Kategori 3S ONE dari judul kelompok di halaman (judul config = judul halaman lama). */
    public static function categoryFor(string $brand, string $title): ?string
    {
        $title = mb_strtolower(self::clean($title));

        foreach (WebsiteCatalog::categoriesFor($brand) as $key => $category) {
            if (mb_strtolower($category['title']) === $title) {
                return $key;
            }
        }

        return null;
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
