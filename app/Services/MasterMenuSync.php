<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Notify;

/**
 * Master Menu mengikuti Menu Utama tim menu (revisi 9 Nov 2026).
 *
 * Tim menu membuat menu di aplikasi Menu; admin tidak lagi membuat menu dan
 * hanya mengisi Harga Non Lapak di Admin App › Harga Menu. Setiap Menu Utama
 * yang dibuat atau disimpan tanpa Master Menu dibuatkan satu, bernama sama
 * dengan resepnya (origin `menu`). Nama dan status aktif menu buatan ini ikut
 * berubah bila resepnya diubah.
 *
 * Menu lama -- sisa V2 atau yang dicocokkan lewat Pencocokan Menu -- tidak
 * diganti namanya: nama itu yang sudah dipakai di PO, invoice, dan pelanggan.
 * Menu lama tanpa resep dinonaktifkan sendiri setelah 90 hari tidak dipakai
 * (perintah menu:deactivate-unused).
 */
class MasterMenuSync
{
    public const DEFAULT_UNIT = 'PORSI';

    /**
     * Buatkan Master Menu untuk Menu Utama yang belum punya. Sub-menu dan
     * resep yang sudah tertaut ke produk dilewati.
     */
    public function ensureFor(Recipe $recipe): ?Product
    {
        if ($recipe->jenis !== Recipe::JENIS_UTAMA || $recipe->products()->exists()) {
            return null;
        }

        $product = new Product([
            'name' => $recipe->name,
            'sku' => $this->uniqueSku($recipe->name),
            'unit' => self::DEFAULT_UNIT,
            // Belum ada harga non lapak sampai admin mengisinya.
            'base_price' => 0,
            'active' => (bool) $recipe->is_active,
            'is_3s' => false,
            'recipe_id' => $recipe->id,
            'needs_recipe' => true,
        ]);
        $product->origin = Product::ORIGIN_MENU;
        $product->save();

        $this->notifyAdmins($product);

        return $product;
    }

    /**
     * Resep diganti nama atau diaktifkan/dinonaktifkan: Master Menu buatan
     * resep itu ikut. Dipanggil dari event model, jadi hanya kolom yang
     * benar-benar berubah yang diteruskan -- menu yang sengaja dinonaktifkan
     * di Master Menu tidak hidup lagi hanya karena resepnya disimpan ulang.
     */
    public function follow(Recipe $recipe): void
    {
        $changes = [];

        if ($recipe->wasChanged('name')) {
            $changes['name'] = $recipe->name;
        }

        if ($recipe->wasChanged('is_active')) {
            $changes['active'] = (bool) $recipe->is_active;
        }

        if ($changes === []) {
            return;
        }

        Product::query()
            ->where('recipe_id', $recipe->id)
            ->where('origin', Product::ORIGIN_MENU)
            ->get()
            ->each(fn (Product $product) => $product->update($changes));
    }

    /** SKU = nama menu di website, wajib unik: nama resep, diberi nomor bila sudah dipakai. */
    protected function uniqueSku(string $name): string
    {
        $base = mb_substr((string) Product::normalizeSku($name), 0, 94);
        $sku = $base;

        for ($n = 2; Product::query()->where('sku', $sku)->exists(); $n++) {
            $sku = $base.' ('.$n.')';
        }

        return $sku;
    }

    /** Admin perlu mengisi Harga Non Lapak sebelum menu ini dipesan customer non lapak. */
    protected function notifyAdmins(Product $product): void
    {
        // whereHas, bukan User::role(): scope itu melempar galat bila peran
        // admin belum pernah dibuat.
        $admins = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'admin'))
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notify::users(
            $admins,
            'Menu baru dari tim menu: '.$product->name,
            'Menu ini sudah bisa dipilih di PO. Isi Harga Non Lapak-nya di Harga Menu.',
            route('adminapp.products.edit', $product),
            'info',
            'Isi harga',
        );
    }
}
