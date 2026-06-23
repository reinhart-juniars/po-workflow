<?php

namespace App\Support;

use Illuminate\Support\Str;

final class UiLabel
{
    private const LABELS = [
        'purchase_order_status' => [
            'pending' => 'Menunggu',
            'draft' => 'Draft',
            'scheduled' => 'Terjadwal',
            'in_progress' => 'Sedang Berlangsung',
            'in_production' => 'Sedang Diproduksi',
            'ready_for_delivery' => 'Siap Dikirim',
            'completed' => 'Selesai',
            'delivered' => 'Terkirim',
            'cancelled' => 'Dibatalkan',
        ],
        'spk_status' => [
            'draft' => 'Draft',
            'in_process' => 'Sedang Diproses',
            'completed' => 'Selesai',
        ],
        'delivery_status' => [
            'ready' => 'Siap Kirim',
            'on_delivery' => 'Sedang Diantar',
            'delivered' => 'Terkirim',
        ],
        'receivable_status' => [
            'unpaid' => 'Belum Dibayar',
            'partial' => 'Dibayar Sebagian',
            'paid' => 'Lunas',
        ],
        'payable_status' => [
            'unpaid' => 'Belum Dibayar',
            'partial' => 'Dibayar Sebagian',
            'paid' => 'Lunas',
        ],
        'payment_type' => [
            'cash' => 'Tunai',
            'receivable' => 'Piutang',
        ],
        'spk_slot' => [
            'fixed_03' => 'Slot 03:00',
            'fixed_07' => 'Slot 07:00',
            'fixed_11' => 'Slot 11:00',
            'custom' => 'Jam Kustom',
        ],
        'audit_entity' => [
            'purchase_orders' => 'Purchase Order',
            'purchase_order' => 'Purchase Order',
            'spk' => 'SPK',
            'delivery_orders' => 'Delivery Order',
            'delivery_order' => 'Delivery Order',
            'sales_actual' => 'Sales Actual',
            'cash_in' => 'Penerimaan Kas',
            'other_income' => 'Pemasukan Lain',
            'cash_out' => 'Pengeluaran',
            'period-closing' => 'Penutupan Periode',
            'period_closing' => 'Penutupan Periode',
        ],
        'audit_action' => [
            'created' => 'Dibuat',
            'updated' => 'Diperbarui',
            'deleted' => 'Dihapus',
            'status_changed' => 'Status Diubah',
            'completed_production' => 'Produksi Selesai',
            'cash_in_ready' => 'Siap Cash In',
            'receivable_period_opened' => 'Piutang Dibuka',
            'receivable_settled' => 'Piutang Dilunasi',
            'scheduled_for_delivery' => 'Dijadwalkan untuk Pengiriman',
            'sales_actual_draft_created' => 'Draft Sales Actual Dibuat',
            'sales_actual_updated' => 'Sales Actual Diperbarui',
            'sales_actual_submitted' => 'Sales Actual Disubmit',
            'sales_actual_cash_in_created' => 'Cash In Sales Actual Dibuat',
            'sales_actual_carry_forward_created' => 'Retur Carry Forward Dibuat',
            'cancelled_by_customer' => 'Dibatalkan oleh Customer',
        ],
    ];

    public static function purchaseOrderStatus(?string $value): string
    {
        return self::label('purchase_order_status', $value);
    }

    public static function purchaseOrderStatusOptions(): array
    {
        return self::options('purchase_order_status');
    }

    public static function spkStatus(?string $value): string
    {
        return self::label('spk_status', $value);
    }

    public static function spkStatusOptions(): array
    {
        return self::options('spk_status');
    }

    public static function deliveryStatus(?string $value): string
    {
        return self::label('delivery_status', $value);
    }

    public static function deliveryStatusOptions(): array
    {
        return self::options('delivery_status');
    }

    public static function receivableStatus(?string $value): string
    {
        return self::label('receivable_status', $value);
    }

    public static function payableStatus(?string $value): string
    {
        return self::label('payable_status', $value);
    }

    public static function paymentType(?string $value): string
    {
        return self::label('payment_type', $value);
    }

    public static function spkSlot(?string $value): string
    {
        return self::label('spk_slot', $value);
    }

    public static function spkSlotOptions(): array
    {
        return self::options('spk_slot');
    }

    public static function auditEntity(?string $value): string
    {
        return self::label('audit_entity', $value);
    }

    public static function auditAction(?string $value): string
    {
        return self::label('audit_action', $value);
    }

    public static function status(?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        foreach ([
            'purchase_order_status',
            'spk_status',
            'delivery_status',
            'receivable_status',
            'payable_status',
        ] as $group) {
            if (isset(self::LABELS[$group][$value])) {
                return self::LABELS[$group][$value];
            }
        }

        return self::humanize($value);
    }

    public static function humanize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return Str::of($value)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }

    private static function label(string $group, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return self::LABELS[$group][$value] ?? self::humanize($value);
    }

    private static function options(string $group): array
    {
        return self::LABELS[$group] ?? [];
    }
}
