<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entity',
        'entity_id',
        'purchase_order_id',
        'action',
        'message',
        'before_json',
        'after_json',
        'ip_address',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json'  => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(\App\Models\PurchaseOrder::class);
    }
}
