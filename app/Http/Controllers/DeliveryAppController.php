<?php

namespace App\Http\Controllers;

use App\Models\{Area, AuditLog, DeliveryOrder};
use App\Services\SalesActualService;
use App\Support\UiLabel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeliveryAppController extends Controller
{
    public function dashboard()
    {
        $dos = DeliveryOrder::with(['purchaseOrders.customer', 'area'])
            ->whereIn('status', ['ready', 'on_delivery'])
            ->whereHas('purchaseOrders')
            ->orderByDesc('scheduled_at')
            ->get();

        return view('deliveryapp.dashboard', compact('dos'));
    }

    public function show(DeliveryOrder $do)
    {
        $do->load(['area', 'purchaseOrders.customer']);

        return view('deliveryapp.show', compact('do'));
    }

    public function start(Request $request, DeliveryOrder $do)
    {
        if ($do->status !== 'ready') {
            return back()->with('error', 'Hanya DO berstatus ' . UiLabel::deliveryStatus('ready') . ' yang bisa dimulai.');
        }

        $before = $do->replicate()->toArray();

        $do->status = 'on_delivery';
        $do->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'delivery_order',
            'entity_id' => $do->id,
            'purchase_order_id' => null,
            'action' => 'status_changed',
            'message' => sprintf(
                'DO %s diubah dari %s menjadi %s oleh %s pada %s',
                $do->do_code,
                UiLabel::deliveryStatus('ready'),
                UiLabel::deliveryStatus('on_delivery'),
                Auth::user()->name ?? 'Unknown',
                now()->format('d-m-Y H:i')
            ),
            'before_json' => json_encode($before),
            'after_json' => json_encode($do->toArray()),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('deliveryapp.orders.show', $do->id)
            ->with('success', "DO {$do->do_code} sekarang berstatus " . UiLabel::deliveryStatus('on_delivery') . '.');
    }

    public function cancel(Request $request, DeliveryOrder $do)
    {
        if ($do->status !== 'on_delivery') {
            return back()->with('error', 'Hanya DO berstatus ' . UiLabel::deliveryStatus('on_delivery') . ' yang bisa dibatalkan.');
        }

        $before = $do->replicate()->toArray();

        $do->status = 'ready';
        $do->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'entity' => 'delivery_order',
            'entity_id' => $do->id,
            'purchase_order_id' => null,
            'action' => 'status_changed',
            'message' => sprintf(
                'DO %s dibatalkan (%s menjadi %s) oleh %s pada %s',
                $do->do_code,
                UiLabel::deliveryStatus('on_delivery'),
                UiLabel::deliveryStatus('ready'),
                Auth::user()->name ?? 'Unknown',
                now()->format('d-m-Y H:i')
            ),
            'before_json' => json_encode($before),
            'after_json' => json_encode($do->toArray()),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('deliveryapp.orders.show', $do->id)
            ->with('success', "DO {$do->do_code} dibatalkan dan kembali ke status " . UiLabel::deliveryStatus('ready') . '.');
    }

    public function complete(Request $request, DeliveryOrder $do)
    {
        if ($do->status !== 'on_delivery') {
            return back()->with('error', 'Hanya DO berstatus ' . UiLabel::deliveryStatus('on_delivery') . ' yang bisa diselesaikan.');
        }

        $before = $do->replicate()->toArray();

        $salesActuals = DB::transaction(function () use ($do, $before, $request) {
            $do->status = 'delivered';
            $do->save();

            $salesActuals = app(SalesActualService::class)->createDraftFromDeliveryOrder($do, $request->ip());

            AuditLog::create([
                'user_id' => Auth::id(),
                'entity' => 'delivery_order',
                'entity_id' => $do->id,
                'purchase_order_id' => null,
                'action' => 'status_changed',
                'message' => sprintf(
                    'DO %s diubah dari %s menjadi %s oleh %s pada %s',
                    $do->do_code,
                    UiLabel::deliveryStatus('on_delivery'),
                    UiLabel::deliveryStatus('delivered'),
                    Auth::user()->name ?? 'Unknown',
                    now()->format('d-m-Y H:i')
                ),
                'before_json' => json_encode($before),
                'after_json' => json_encode($do->toArray()),
                'ip_address' => $request->ip(),
            ]);

            return $salesActuals;
        });

        return redirect()
            ->route('deliveryapp.dashboard')
            ->with('success', "DO {$do->do_code} ditandai " . UiLabel::deliveryStatus('delivered') . ". Draft sales actual dibuat: {$salesActuals->count()}.");
    }
}
