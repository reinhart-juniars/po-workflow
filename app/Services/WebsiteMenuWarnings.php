<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Support\Notify;
use App\Support\WebsiteCatalog;
use Illuminate\Support\Facades\Auth;

/**
 * Bagian C: peringatan untuk marketing tentang menu yang dicentang Tampil di
 * website tetapi tidak akan ikut terbit.
 *
 * Halaman website (3S / Warung Wuenak Poll) ikut tanda Menu 3S di Master Menu,
 * yang dikelola tim menu, sedangkan kategori website dikelola marketing. Bila
 * tanda itu berubah (atau menunya dinonaktifkan) saat menunya tampil di
 * website, kategorinya tidak lagi cocok dan menu itu diam-diam hilang dari
 * terbitan berikutnya. Marketing diberi tahu dua cara:
 * - kotak peringatan di Dashboard dan Katalog (selalu terlihat sampai dibetulkan);
 * - lonceng saat perubahannya terjadi (kecuali bila pelakunya sendiri).
 */
class WebsiteMenuWarnings
{
    public const RECIPIENT_ROLES = ['marketing'];

    /** Jenis masalah => [judul, cara membetulkan], urut dari yang paling sering. */
    public const TYPES = [
        'salah-halaman' => [
            'Kategori milik halaman lain',
            'Tanda Menu 3S menunya berbeda dengan halaman kategorinya. Pilih kategori baru di Katalog (menu pindah halaman), atau minta tim menu mengubah tanda Menu 3S di Master Menu.',
        ],
        'tanpa-kategori' => ['Belum diberi kategori website', 'Pilih kategorinya di Katalog.'],
        'tanpa-sku' => ['Belum punya SKU (nama di website)', 'Isi SKU di Katalog atau Master Menu.'],
        'nonaktif' => [
            'Menu nonaktif di Master Menu',
            'Lepas centang Tampil di website, atau minta tim menu mengaktifkannya lagi.',
        ],
    ];

    /**
     * Menu yang dicentang Tampil di website tetapi tidak akan ikut terbit.
     *
     * @return list<array{product: Product, type: string, reason: string}>
     */
    public function excluded(): array
    {
        return Product::query()
            ->where('show_on_website', true)
            ->whereNotIn('id', Product::query()->onWebsite()->select('id'))
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'active', 'is_3s', 'website_category'])
            ->map(function (Product $product) {
                $type = $this->typeOf($product);

                return [
                    'product' => $product,
                    'type' => $type,
                    'reason' => $type === 'salah-halaman'
                        ? 'Kategori '.(WebsiteCatalog::title($product->website_category) ?? $product->website_category)
                            .' bukan milik halaman '.$this->pageLabel($product)
                        : self::TYPES[$type][0],
                ];
            })
            ->all();
    }

    /**
     * Ringkasan per jenis untuk kotak peringatan.
     *
     * @param  list<array{product: Product, type: string, reason: string}>  $excluded
     * @return array<string, array{title: string, hint: string, count: int, names: list<string>}>
     */
    public function summary(array $excluded): array
    {
        $groups = collect($excluded)->groupBy('type');
        $summary = [];

        foreach (self::TYPES as $type => [$title, $hint]) {
            if ($rows = $groups->get($type)) {
                $summary[$type] = [
                    'title' => $title,
                    'hint' => $hint,
                    'count' => $rows->count(),
                    'names' => $rows->map(fn ($row) => $row['product']->sku ?: $row['product']->name)->all(),
                ];
            }
        }

        return $summary;
    }

    /**
     * Dipanggil dari hook model saat Product diperbarui: lonceng ke marketing
     * bila menu yang tampil di website pindah halaman atau dinonaktifkan.
     */
    public function productUpdated(Product $product): void
    {
        if (! $product->show_on_website) {
            return;
        }

        if ($product->wasChanged('active') && ! $product->active) {
            $title = 'Menu website dinonaktifkan: '.($product->sku ?: $product->name);
            $body = $product->name.' dinonaktifkan di Master Menu, jadi tidak ikut terbit ke website berikutnya. '
                .self::TYPES['nonaktif'][1];
        } elseif ($product->wasChanged('is_3s') && $product->active) {
            $title = 'Menu website pindah halaman: '.($product->sku ?: $product->name);
            $body = $product->name.' sekarang bertanda '.$this->pageLabel($product)
                .'. Kategori websitenya ('.(WebsiteCatalog::title($product->website_category) ?? 'kosong').') milik halaman lain, '
                .'jadi menu ini tidak ikut terbit sampai kategorinya dipilih ulang di Katalog.';
        } else {
            return;
        }

        $actor = Auth::user();
        $recipients = User::query()
            ->where('is_active', true)
            // whereHas, bukan scope role(): scope itu melempar exception bila
            // perannya belum dibuat (lihat SkuChangeNotifier).
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::RECIPIENT_ROLES))
            ->when($actor, fn ($query) => $query->whereKeyNot($actor->id))
            ->get();

        Notify::users($recipients, $title, $body.($actor ? ' Diubah oleh '.$actor->name.'.' : ''),
            route('marketingapp.catalog.index', ['website' => 'tanpa-kategori', 'aktif' => 0]), 'warning', 'Buka katalog');
    }

    private function typeOf(Product $product): string
    {
        return match (true) {
            ! $product->active => 'nonaktif',
            blank($product->sku) => 'tanpa-sku',
            blank($product->website_category) => 'tanpa-kategori',
            default => 'salah-halaman',
        };
    }

    private function pageLabel(Product $product): string
    {
        return WebsiteCatalog::brands()[WebsiteCatalog::brandOf($product)]['label'];
    }
}
