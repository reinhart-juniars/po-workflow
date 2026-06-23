<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model {
    use HasFactory;
    protected $fillable=['name','phone','address','area_id','active','is_lapak'];
    protected $casts = [
        'active' => 'boolean',
        'is_lapak' => 'boolean',
    ];
    public function area(){return $this->belongsTo(Area::class);}
    public function purchaseOrders(){return $this->hasMany(PurchaseOrder::class);} 
    public function salesActuals(){return $this->hasMany(SalesActual::class);}
}
