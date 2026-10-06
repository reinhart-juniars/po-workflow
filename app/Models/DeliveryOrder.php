<?php

namespace App\Models;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Helpers\AutoNumberHelper;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\PurchaseOrder;

class DeliveryOrder extends Model {
    use HasFactory;
    protected $fillable=[
        'do_code',
        'area_id',
        'scheduled_at',
        'driver_user_id',
        'status',
        'notes'
    ];
        protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    protected static function booted(): void 
    {
        static::creating(function ($model) {
            // Auto number
            if (empty($model->do_code)) {
                $model->do_code = \App\Helpers\AutoNumberHelper::generate('delivery_orders','do_code','DO');
            }
            // Default status
            if (empty($model->status)) {
                $model->status = 'ready';
            }
            // Optional: created_by
            if (empty($model->created_by) && Auth::id()) {
                $model->created_by = Auth::id();
            }
        });
    }
    public function area(){
        return $this->belongsTo(
            Area::class
        );
    } 
    public function driver(){
        return $this->belongsTo(
            User::class,'driver_user_id'
        );
    }
    public function purchaseOrders(){
        return $this->belongsToMany(
            PurchaseOrder::class,
            'delivery_order_purchase_orders',
            'delivery_order_id',
            'purchase_order_id'
        );
    }
    public function proofs(){
        return $this->hasMany(
            ProofOfDelivery::class
        );
    } 
    public function salesActuals(){
        return $this->hasMany(
            SalesActual::class
        );
    }
}
