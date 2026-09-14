<?php

namespace App\Support;

use App\Models\User;

/**
 * Daftar aplikasi 3S BCS yang bisa dibuka seorang pengguna, dipakai header
 * Blade dan topbar Inventory App supaya keduanya menampilkan pilihan yang
 * sama. Kuncinya mengikuti User::accessibleAppKeys().
 */
class AppSwitcher
{
    /** @return array<string, array{label: string, title: string, url: string, pattern: string}> */
    public static function apps(): array
    {
        return [
            'superadmin' => ['label' => 'Superadmin', 'title' => 'Superadmin Dashboard', 'url' => route('superadmin.dashboard'), 'pattern' => 'superadmin/*'],
            'owner' => ['label' => 'Owner', 'title' => 'Owner App', 'url' => route('ownerapp.dashboard'), 'pattern' => 'owner-app*'],
            'admin' => ['label' => 'Admin', 'title' => 'Admin App', 'url' => route('adminapp.dashboard'), 'pattern' => 'admin-app*'],
            'accounting' => ['label' => 'Accounting', 'title' => 'Accounting App', 'url' => route('accountingapp.dashboard'), 'pattern' => 'accounting-app*'],
            'inventory' => ['label' => 'Inventory', 'title' => 'Inventory App', 'url' => route('filament.admin.pages.dashboard'), 'pattern' => 'inventory-app*'],
            'sales' => ['label' => 'Sales', 'title' => 'Sales App', 'url' => route('salesapp.dashboard'), 'pattern' => 'sales-app*'],
            'production' => ['label' => 'Production', 'title' => 'Production App', 'url' => route('productionapp.dashboard'), 'pattern' => 'production-app*'],
            'delivery' => ['label' => 'Delivery', 'title' => 'Delivery App', 'url' => route('deliveryapp.dashboard'), 'pattern' => 'delivery-app*'],
        ];
    }

    /**
     * Tautan pengalih untuk pengguna ini, tanpa aplikasi yang sedang dibuka.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public static function linksFor(?User $user, ?string $currentApp = null): array
    {
        if (! $user) {
            return [];
        }

        $apps = self::apps();
        $links = [];

        foreach ($user->accessibleAppKeys() as $key) {
            if (! isset($apps[$key]) || $key === $currentApp || request()->is($apps[$key]['pattern'])) {
                continue;
            }

            $links[] = ['key' => $key, 'label' => $apps[$key]['label'], 'url' => $apps[$key]['url']];
        }

        return $links;
    }
}
