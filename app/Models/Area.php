<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Area extends Model {
    use HasFactory;
    protected $fillable=['name','code'];
    public function customers(){return $this->hasMany(Customer::class);}
    public function purchaseOrders(){return $this->hasMany(PurchaseOrder::class);} 
    public function deliveryOrders(){return $this->hasMany(DeliveryOrder::class);} 
}
