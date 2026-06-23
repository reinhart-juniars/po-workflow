<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_number',
        'customer_id',
        'recipient_name',
        'shipping_address',
        'area_id',
        'delivery_date',
        'delivery_time',
        'discount_amount',
        'shipping_cost',
        'payment_type',
        'receivable_days',
        'due_date',
        'cash_received_at',
        'cash_received_by',
        'cash_account_id',
        'receivable_status',
        'status',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'created_by',
        'updated_by',
        'total_qty',
        'total_amount',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'due_date' => 'date',
        'cash_received_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancelled_by' => 'integer',
        'receivable_days' => 'integer',
        'cash_received_by' => 'integer',
        'cash_account_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            // auto-number (kalau ada)
            if (empty($model->po_number)) {
                $model->po_number = \App\Helpers\AutoNumberHelper::generate('purchase_orders', 'po_number', 'PO');
            }

            // set created_by secara aman (CLI / seeder / web)
            if (empty($model->created_by)) {
                $userId = Auth::id(); // null kalau tidak ada user login
                if ($userId) {
                    $model->created_by = $userId;
                }
            }
        });

        static::creating(function ($po) {
            if (empty($po->status)) {
                $po->status = 'pending';
            }

            $po->completed_at = $po->status === 'completed'
                ? ($po->completed_at ?? now())
                : null;
        });

        static::updating(function (self $po) {
            if ($po->isDirty('status')) {
                $po->completed_at = $po->status === 'completed' ? now() : null;

                if ($po->status === 'completed') {
                    if ($po->payment_type === 'cash') {
                        $po->cash_received_at = null;
                        $po->cash_received_by = null;
                        $po->receivable_status = null;
                    } elseif ($po->payment_type === 'receivable') {
                        $po->receivable_status = $po->receivable_status ?? 'unpaid';
                    }
                }
            }

            // jika status berubah ke non-pending tapi belum ada item, tolak
            $target = $po->status;
            $blocked = in_array($target, ['scheduled', 'in_progress', 'ready_for_delivery', 'completed']);
            if ($blocked && $po->items()->count() === 0) {
                throw ValidationException::withMessages([
                    'items' => 'Tambahkan minimal 1 item sebelum mengubah status.',
                ]);
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function spks()
    {
        return $this->belongsToMany(
            Spk::class,
            'spk_purchase_orders',
            'purchase_order_id',
            'spk_id'
        );
    }

    public function deliveryOrders()
    {
        return $this->belongsToMany(
            DeliveryOrder::class,
            'delivery_order_purchase_orders',
            'purchase_order_id',
            'delivery_order_id'
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashReceiver()
    {
        return $this->belongsTo(User::class, 'cash_received_by');
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function proofs()
    {
        return $this->hasMany(ProofOfDelivery::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function hasDeliveredDeliveryOrder(): bool
    {
        if (in_array($this->status, ['delivered'], true)) {
            return true;
        }

        if ($this->relationLoaded('deliveryOrders')) {
            return $this->deliveryOrders
                ->contains(fn (DeliveryOrder $deliveryOrder) => in_array($deliveryOrder->status, ['delivered', 'completed'], true));
        }

        return $this->deliveryOrders()
            ->whereIn('status', ['delivered', 'completed'])
            ->exists();
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function recalcTotals(): void
    {
        $qty = (int) $this->items()->sum('qty');
        $sum = (float) $this->items()->sum('subtotal');

        $this->forceFill([
            'total_qty' => $qty,
            'total_amount' => $sum,
        ])->saveQuietly();
    }

    public function auditLogs()
    {
        return $this->hasMany(\App\Models\AuditLog::class);
    }

    public function scopeOpenReceivable(Builder $query): Builder
    {
        return $query
            ->where('status', 'completed')
            ->where('payment_type', 'receivable')
            ->where(function (Builder $receivableQuery) {
                $receivableQuery
                    ->whereIn('receivable_status', ['unpaid', 'partial'])
                    ->orWhere(function (Builder $legacyQuery) {
                        $legacyQuery
                            ->whereNull('receivable_status')
                            ->whereNull('cash_received_at');
                    });
            });
    }
}
