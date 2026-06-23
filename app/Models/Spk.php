<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Helpers\AutoNumberHelper;

class Spk extends Model {
    use HasFactory;
    
    protected $fillable=[
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

    public function responsible(){
        return $this->belongsTo(User::class,'responsible_user_id');
    }

    public function purchaseOrders(){
        // spk_purchase_orders: spk_id, purchase_order_id
        return $this->belongsToMany(
            PurchaseOrder::class,
            'spk_purchase_orders',
            'spk_id',
            'purchase_order_id'
        );
    }
}
