<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Support\Facades\Auth;

/**
 * Penggantian SKU menu (= nama menu di website).
 *
 * Admin (Master Menu) dan marketing (Katalog Foto Menu) sama-sama boleh
 * mengganti SKU. Supaya tidak saling timpa tanpa tahu:
 * - setiap penggantian dicatat di audit log (SKU lama -> baru), apa pun jalurnya;
 * - bila menunya sedang tampil di website, admin dan marketing lain menerima
 *   lonceng. Menu yang belum tampil tidak memicu lonceng, supaya merapikan
 *   ratusan SKU lama sebelum terbit tidak membanjiri lonceng.
 * Pelaku perubahan sendiri tidak diberi tahu.
 */
class SkuChangeNotifier
{
    public const RECIPIENT_ROLES = ['admin', 'marketing'];

    public function skuChanged(Product $product, ?string $old, ?string $new): void
    {
        $actor = Auth::user();

        AuditLog::create([
            'user_id' => $actor?->id,
            'entity' => 'product',
            'entity_id' => $product->id,
            'action' => 'sku_changed',
            'message' => 'SKU menu '.$product->name.': '.($old ?? '(kosong)').' → '.($new ?? '(kosong)'),
            'ip_address' => request()?->ip(),
        ]);

        if ($old === null || ! $product->active || ! $product->show_on_website) {
            return;
        }

        $recipients = User::query()
            ->where('is_active', true)
            // whereHas, bukan scope role(): scope itu melempar exception bila salah
            // satu peran belum dibuat (mis. `marketing` sebelum access:sync).
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::RECIPIENT_ROLES))
            ->when($actor, fn ($query) => $query->whereKeyNot($actor->id))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $title = 'Nama menu di website diganti: '.$new;
        $body = 'SKU '.$old.' → '.$new.' ('.$product->name.')'
            .($actor ? ' oleh '.$actor->name : '').'. Nama ini yang tampil di website.';

        // Tautan mengikuti aplikasi yang bisa dibuka penerima: admin ke Master
        // Menu, marketing (tanpa peran admin) ke Katalog Foto Menu.
        [$admins, $marketing] = $recipients->partition(fn (User $user) => $user->hasRole('admin'));

        Notify::users($admins, $title, $body, route('adminapp.products.edit', $product), 'info', 'Buka menu');
        Notify::users($marketing, $title, $body, route('marketingapp.catalog.index', ['q' => $new]), 'info', 'Buka katalog');
    }
}
