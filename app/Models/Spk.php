<?php

namespace App\Models;

use App\Helpers\AutoNumberHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Spk extends Model
{
    use HasFactory;

    protected $fillable = [
        'spk_code',
        'scheduled_at',
        'slot_type',
        'responsible_user_id',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->spk_code)) {
                $model->spk_code = AutoNumberHelper::generate('spks', 'spk_code', 'SPK');
            }
            if (empty($model->status)) {
                $model->status = 'draft'; // ✅ fallback di model
            }
        });
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** SPK Produksi (modul inventory) yang disusun dari slot ini. */
    public function productionOrder(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProductionOrder::class, 'spk_id');
    }

    public function purchaseOrders()
    {
        // spk_purchase_orders: spk_id, purchase_order_id
        return $this->belongsToMany(
            PurchaseOrder::class,
            'spk_purchase_orders',
            'spk_id',
            'purchase_order_id'
        );
    }
}
