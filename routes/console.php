<?php

use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('po:audit-cash-in {--fix : Clear legacy cash_received_at on cash PO}', function () {
    $rows = PurchaseOrder::query()
        ->with('customer:id,name')
        ->where('payment_type', 'cash')
        ->whereNotNull('cash_received_at')
        ->orderBy('cash_received_at')
        ->orderBy('po_number')
        ->get([
            'id',
            'po_number',
            'customer_id',
            'status',
            'completed_at',
            'cash_received_at',
            'cash_received_by',
            'cash_account_id',
            'total_amount',
        ]);

    if ($rows->isEmpty()) {
        $this->info('Tidak ada PO cash legacy yang punya cash_received_at.');

        return self::SUCCESS;
    }

    $this->warn('Ditemukan PO cash legacy yang masih punya cash_received_at:');
    $this->table(
        ['ID', 'PO', 'Customer', 'Status', 'Completed', 'Cash Received', 'Cash Account', 'Amount'],
        $rows->map(fn (PurchaseOrder $po) => [
            $po->id,
            $po->po_number,
            $po->customer?->name ?? '-',
            $po->status,
            optional($po->completed_at)?->format('Y-m-d H:i:s'),
            optional($po->cash_received_at)?->format('Y-m-d H:i:s'),
            $po->cash_account_id,
            number_format((float) $po->total_amount, 0, ',', '.'),
        ])->all()
    );

    $this->line('Total suspicious rows: ' . $rows->count());
    $this->line('Total nominal: Rp ' . number_format((float) $rows->sum('total_amount'), 0, ',', '.'));

    if (! $this->option('fix')) {
        $this->comment('Run `php artisan po:audit-cash-in --fix` untuk membersihkan field legacy tersebut.');

        return self::SUCCESS;
    }

    DB::transaction(function () use ($rows) {
        foreach ($rows as $po) {
            $before = [
                'payment_type' => $po->payment_type,
                'cash_received_at' => optional($po->cash_received_at)?->toDateTimeString(),
                'cash_received_by' => $po->cash_received_by,
                'cash_account_id' => $po->cash_account_id,
                'status' => $po->status,
                'total_amount' => (float) $po->total_amount,
            ];

            $po->forceFill([
                'cash_received_at' => null,
                'cash_received_by' => null,
            ])->save();

            AuditLog::query()->create([
                'user_id' => null,
                'entity' => 'purchase_order',
                'entity_id' => $po->id,
                'purchase_order_id' => $po->id,
                'action' => 'legacy_cash_in_cleared',
                'message' => sprintf(
                    'Legacy cash receipt pada PO %s dibersihkan via command po:audit-cash-in.',
                    $po->po_number
                ),
                'before_json' => $before,
                'after_json' => [
                    ...$before,
                    'cash_received_at' => null,
                    'cash_received_by' => null,
                ],
                'ip_address' => 'console',
            ]);
        }
    });

    $this->info('Legacy cash_received_at untuk PO cash berhasil dibersihkan.');

    return self::SUCCESS;
})->purpose('Audit legacy cash receipts on cash purchase orders');
