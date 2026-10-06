<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Support\Notify;
use Illuminate\Support\Facades\Auth;

/**
 * Bagian B.1 -- Notifikasi Perubahan Harga.
 *
 * Lapisan di atas histori harga yang sudah ada: setiap kali harga beli bahan
 * (InventoryItem) atau harga jual menu (Product) berubah, pemegang izin
 * `notification.price` menerima lonceng berisi angka lama -> baru, persen
 * perubahan, dan sumbernya (panel, import, atau nomor Form Kebutuhan).
 * Pelaku perubahan sendiri tidak diberi tahu.
 *
 * Perubahan harga juga menjadwalkan pemeriksaan batas profit menu (B.2)
 * sekali di akhir request, supaya 30 baris form yang diperiksa sekaligus
 * tidak memicu 30 perhitungan.
 */
class PriceChangeNotifier
{
    protected static bool $profitCheckScheduled = false;

    public function ingredientChanged(InventoryItem $item, ?float $old, ?float $new, ?string $source): void
    {
        if ($old === null || $new === null || abs($old - $new) < 0.00005) {
            return;
        }

        $arah = $new > $old ? 'naik' : 'turun';
        $pct = $old > 0 ? round(abs($new - $old) / $old * 100, 1) : null;

        Notify::permission(
            'notification.price',
            'Harga bahan '.$item->name.' '.$arah.($pct !== null ? ' '.$pct.'%' : ''),
            Notify::rupiah($old).' → '.Notify::rupiah($new).' per '.$item->unit
                .($source ? ' · sumber: '.$source : '').'. HPP resep yang memakainya ikut berubah.',
            \App\Filament\Resources\InventoryItemResource::getUrl('edit', ['record' => $item]),
            $new > $old ? 'warning' : 'info',
            Auth::id(),
            'Buka bahan',
        );

        $this->scheduleProfitCheck();
    }

    public function productPriceChanged(Product $product, ?float $old, ?float $new): void
    {
        if ($old === null || $new === null || abs($old - $new) < 0.005) {
            return;
        }

        $arah = $new > $old ? 'naik' : 'turun';
        $pct = $old > 0 ? round(abs($new - $old) / $old * 100, 1) : null;

        Notify::permission(
            'notification.price',
            'Harga jual '.$product->name.' '.$arah.($pct !== null ? ' '.$pct.'%' : ''),
            Notify::rupiah($old).' → '.Notify::rupiah($new).' per '.$product->unit
                .($product->sku ? ' · SKU '.$product->sku : '').'.',
            route('adminapp.products.edit', $product),
            $new > $old ? 'info' : 'warning',
            Auth::id(),
            'Buka menu',
        );

        $this->scheduleProfitCheck();
    }

    /**
     * Pemeriksaan batas profit dijalankan sekali per request, setelah respons
     * dikirim, apa pun jumlah perubahan harga di dalamnya.
     */
    protected function scheduleProfitCheck(): void
    {
        if (static::$profitCheckScheduled) {
            return;
        }

        static::$profitCheckScheduled = true;

        app()->terminating(function () {
            static::$profitCheckScheduled = false;
            app(ProfitGuardService::class)->check(Auth::id());
        });
    }
}
