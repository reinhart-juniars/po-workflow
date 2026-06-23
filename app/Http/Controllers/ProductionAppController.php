<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\{PurchaseOrder, PurchaseOrderItem, Product, Customer, Area, Spk};
use App\Models\AuditLog;
use App\Support\UiLabel;

class ProductionAppController extends Controller
{
    public function dashboard()
    {
        $scheduleFilter = request()->input('schedule_filter', 'all');

        if (! in_array($scheduleFilter, ['all', 'scheduled', 'unscheduled'], true)) {
            $scheduleFilter = 'all';
        }

        $allPos = PurchaseOrder::with([
                'customer',
                'area',
                'spks' => fn ($q) => $q->orderBy('scheduled_at', 'asc'),
            ])
            ->where('status', 'in_progress')
            ->orderBy('po_number')
            ->get();

        $pos = $allPos
            ->when($scheduleFilter === 'scheduled', fn ($collection) => $collection->filter(fn ($po) => optional($po->spks->first())->scheduled_at))
            ->when($scheduleFilter === 'unscheduled', fn ($collection) => $collection->filter(fn ($po) => ! optional($po->spks->first())->scheduled_at))
            ->values();

        return view('productionapp.dashboard', compact('pos', 'allPos', 'scheduleFilter'));
    }

    public function show(PurchaseOrder $po)
    {
        $po->load([
            'customer',
            'area',
            'items.product',
            'spks' => fn ($q) => $q->orderBy('scheduled_at', 'asc'),
        ]);

        // Ambil SPK pertama (paling awal)
        $spk = $po->spks->first();
        $scheduledAt = optional($spk)->scheduled_at;

        return view('productionapp.show', compact('po', 'scheduledAt'));
    }

    public function complete(Request $request, PurchaseOrder $po)
    {
        if ($po->status !== 'in_progress') {
            return back()->with('error', 'PO tidak berstatus ' . UiLabel::purchaseOrderStatus('in_progress') . '.');
        }
        $before = $po->toArray();

        if ($po->payment_type === 'cash' && ! $po->cash_account_id) {
            return back()->with('error', 'PO tunai harus memiliki akun kas terlebih dahulu.');
        }

        $po->status = 'completed';
        $po->completed_at = now();
        if ($po->payment_type === 'cash') {
            $po->cash_received_at = null;
            $po->cash_received_by = null;
            $po->receivable_status = null;
        } elseif ($po->payment_type === 'receivable') {
            $po->cash_received_at = null;
            $po->cash_received_by = null;
            $po->receivable_status = $po->receivable_status ?: 'unpaid';
        }
        $po->updated_by = Auth::id();
        $po->save();
        $this->syncRelatedSpkStatuses($po);

        $after = $po->fresh()->toArray();

        AuditLog::create([
            'user_id'           => Auth::id(),
            'entity'            => 'purchase_order',
            'entity_id'         => $po->id,
            'purchase_order_id' => $po->id,
            'action'            => 'completed_production',
            'message'           => sprintf(
                'PO %s diselesaikan di produksi oleh %s pada %s',
                $po->po_number,
                Auth::user()->name ?? 'Unknown',
                now()->format('d-m-Y H:i')
            ),
            'before_json'       => json_encode($before),
            'after_json'        => json_encode($after),
            'ip_address'        => $request->ip(),
        ]);

        if ($po->payment_type === 'cash') {
            AuditLog::create([
                'user_id' => Auth::id(),
                'entity' => 'cash_in',
                'entity_id' => $po->id,
                'purchase_order_id' => $po->id,
                'action' => 'cash_in_ready',
                'message' => sprintf(
                    'PO %s selesai. Pembayaran tunai Rp %s siap dicatat sebagai cash in.',
                    $po->po_number,
                    number_format((float) $po->total_amount, 0, ',', '.')
                ),
                'before_json' => null,
                'after_json' => [
                    'po_number' => $po->po_number,
                    'payment_type' => $po->payment_type,
                    'total_amount' => $po->total_amount,
                    'completed_at' => optional($po->completed_at)?->toDateTimeString(),
                    'cash_received_at' => optional($po->cash_received_at)?->toDateTimeString(),
                    'cash_received_by' => $po->cash_received_by,
                ],
                'ip_address' => $request->ip(),
            ]);
        } elseif ($po->payment_type === 'receivable') {
            $dueDate = $po->due_date;
            $daysRemaining = $dueDate
                ? now()->startOfDay()->diffInDays($dueDate->copy()->startOfDay(), false)
                : null;

            AuditLog::create([
                'user_id' => Auth::id(),
                'entity' => 'purchase_order',
                'entity_id' => $po->id,
                'purchase_order_id' => $po->id,
                'action' => 'receivable_period_opened',
                'message' => sprintf(
                    'PO %s masuk menu Periode (piutang). Jatuh tempo: %s.',
                    $po->po_number,
                    $dueDate ? $dueDate->format('d-m-Y') : '-'
                ),
                'before_json' => null,
                'after_json' => [
                    'po_number' => $po->po_number,
                    'payment_type' => $po->payment_type,
                    'receivable_days' => $po->receivable_days,
                    'due_date' => $dueDate?->toDateString(),
                    'receivable_status' => $po->receivable_status,
                    'days_remaining' => $daysRemaining,
                    'total_amount' => $po->total_amount,
                ],
                'ip_address' => $request->ip(),
            ]);
        }

        return redirect()
            ->route('productionapp.dashboard')
            ->with('success', "PO {$po->po_number} ditandai " . UiLabel::purchaseOrderStatus('completed') . '.');
    }

    protected function syncRelatedSpkStatuses(PurchaseOrder $po): void
    {
        $po->loadMissing('spks');

        foreach ($po->spks as $spk) {
            $this->recalcSpkStatus($spk);
        }
    }

    protected function recalcSpkStatus(Spk $spk): void
    {
        $totalPo = $spk->purchaseOrders()->count();

        if ($totalPo === 0) {
            $targetStatus = 'draft';
        } else {
            $completedPo = $spk->purchaseOrders()
                ->where('purchase_orders.status', 'completed')
                ->count();

            $targetStatus = $completedPo >= $totalPo ? 'completed' : 'in_process';
        }

        if ($spk->status !== $targetStatus) {
            $spk->status = $targetStatus;
            $spk->updated_by = Auth::id();
            $spk->save();
        }
    }

    public function cancel(Request $request, PurchaseOrder $po)
    {
        if ($po->status !== 'in_progress') {
            return back()->with('error', 'Hanya PO berstatus ' . UiLabel::purchaseOrderStatus('in_progress') . ' yang bisa dibatalkan.');
        }

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'cancel_reason.required' => 'Alasan pembatalan wajib diisi.',
            'cancel_reason.min' => 'Alasan pembatalan minimal 3 karakter.',
        ]);

        $po->load('spks');

        $before = $po->toArray();
        $previousSpks = $po->spks->map(fn (Spk $spk) => [
            'id' => $spk->id,
            'spk_code' => $spk->spk_code,
        ])->all();

        DB::transaction(function () use ($po, $validated) {
            $relatedSpks = $po->spks()->get();

            $po->spks()->detach();

            $po->status = 'cancelled';
            $po->cancelled_at = now();
            $po->cancelled_by = Auth::id();
            $po->cancel_reason = $validated['cancel_reason'];
            $po->completed_at = null;
            $po->cash_received_at = null;
            $po->cash_received_by = null;
            $po->receivable_status = null;
            $po->updated_by = Auth::id();
            $po->save();

            foreach ($relatedSpks as $spk) {
                $this->recalcSpkStatus($spk);
            }
        });

        $after = $po->fresh()->toArray();

        AuditLog::create([
            'user_id'           => Auth::id(),
            'entity'            => 'purchase_order',
            'entity_id'         => $po->id,
            'purchase_order_id' => $po->id,
            'action'            => 'cancelled_by_customer',
            'message'           => sprintf(
                'PO %s dibatalkan oleh %s pada %s. Alasan: %s. SPK terkait dilepas: %s.',
                $po->po_number,
                Auth::user()->name ?? 'Unknown',
                now()->format('d-m-Y H:i'),
                $validated['cancel_reason'],
                $previousSpks ? collect($previousSpks)->pluck('spk_code')->join(', ') : '-'
            ),
            'before_json'       => json_encode($before),
            'after_json'        => json_encode($after + ['detached_spks' => $previousSpks]),
            'ip_address'        => $request->ip(),
        ]);

        return redirect()
            ->route('productionapp.dashboard')
            ->with('success', "PO {$po->po_number} ditandai " . UiLabel::purchaseOrderStatus('cancelled') . '.');
    }
}
