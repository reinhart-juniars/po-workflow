<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'address', 'area_id', 'active', 'is_lapak', 'is_internal'];

    protected $casts = [
        'active' => 'boolean',
        'is_lapak' => 'boolean',
        'is_internal' => 'boolean',
    ];

    /** Customer pembawa Barang Sisa Rencana Masak; bukan pembeli sungguhan. */
    public const INTERNAL_READY_STOCK = 'Stok Siap Dapur (internal)';

    public function scopeExternal($query)
    {
        return $query->where('is_internal', false);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function salesActuals()
    {
        return $this->hasMany(SalesActual::class);
    }
}
