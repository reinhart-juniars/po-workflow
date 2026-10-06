<?php

namespace App\Models\Concerns;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Menjaga supplier_id dan supplier_name tetap sejalan.
 *
 * supplier_name adalah salinan nama saat transaksi dan masih dibaca langsung
 * oleh laporan hutang, neraca, dan export. Karena itu:
 * - supplier_id diisi (dari form yang memilih master) -> nama ikut disalin;
 * - hanya nama yang diisi (form Blade teks bebas) -> ditautkan ke master bila
 *   namanya cocok, dibiarkan tanpa tautan bila tidak.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait LinksSupplier
{
    public static function bootLinksSupplier(): void
    {
        static::saving(function (self $model) {
            if ($model->isDirty('supplier_id') && $model->supplier_id !== null) {
                $name = Supplier::query()->whereKey($model->supplier_id)->value('name');

                if ($name !== null) {
                    $model->supplier_name = $name;
                }

                return;
            }

            if ($model->isDirty('supplier_name')) {
                $supplier = Supplier::findByName($model->supplier_name);
                $model->supplier_id = $supplier?->id;

                // Ejaan diseragamkan ke master: laporan hutang mengelompokkan
                // per supplier_name, jadi "toko makmur" dan "Toko Makmur"
                // tidak boleh menjadi dua baris.
                if ($supplier) {
                    $model->supplier_name = $supplier->name;
                }
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
