<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProofOfDelivery extends Model {
    use HasFactory;
    protected $fillable=['delivery_order_id','purchase_order_id','photo_path','received_by','received_at','notes'];
    public function deliveryOrder(){return $this->belongsTo(DeliveryOrder::class);} 
    public function purchaseOrder(){return $this->belongsTo(PurchaseOrder::class);} 
}
