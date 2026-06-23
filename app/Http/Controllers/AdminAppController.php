<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;   // ⬅️ penting
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use App\Models\AuditLog;
use App\Models\{PurchaseOrder, PurchaseOrderItem, Product, Customer, Area, Spk, DeliveryOrder, User, CashAccount};
use App\Support\UiLabel;
use App\Exports\ProductsExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @method void middleware(array|string $middleware, array $options = [])
 */
class AdminAppController extends Controller
{
    public function dashboard(Request $request)
    {
        $statusFilter = (string) $request->input('status_filter', 'all');

        if (! in_array($statusFilter, ['all', 'draft', 'in_progress'], true)) {
            $statusFilter = 'all';
        }

        // Hitung semua PO yang masih aktif (tanpa filter tanggal)
        $poDraftCount = PurchaseOrder::where('status', 'draft')->count();
        $poProgCount  = PurchaseOrder::where('status', 'in_progress')->count();

        // Ambil semua PO draft & in_progress, urutkan yang paling baru dulu.
        $openPos = PurchaseOrder::with(['customer', 'area'])
            ->whereIn('status', ['draft', 'in_progress'])
            ->orderByDesc('delivery_date')   // kalau kosong bisa di-fallback created_at
            ->orderByDesc('delivery_time')
            ->orderByDesc('created_at')
            ->get();

        $filteredPos = $openPos
            ->when($statusFilter === 'draft', fn ($collection) => $collection->where('status', 'draft'))
            ->when($statusFilter === 'in_progress', fn ($collection) => $collection->where('status', 'in_progress'))
            ->values();

        // supaya Blade kamu tidak perlu diubah banyak
        return view('adminapp.dashboard', [
            'poTodayDraft' => $poDraftCount,
            'poTodayProg'  => $poProgCount,
            'posToday'     => $filteredPos,
            'allPosToday'  => $openPos,
            'statusFilter' => $statusFilter,
        ]);
    }

    public function ordersIndex()
    {
        $today = Carbon::today();

        // 🔹 ambil master customer aktif
        $customers = Customer::select('id','name','phone','address','area_id')
            ->where('active', true)
            ->orderBy('name')
            ->get();

        // (kalau kamu juga mau master produk buat detail item)
        $products = Product::where('active', true)
            ->orderBy('name')
            ->get('id','name','phone');

        $poDraft = PurchaseOrder::with(['customer','area'])
            ->whereDate('created_at',$today)
            ->where('status','draft')
            ->latest('created_at')
            ->get();

        $poProg = PurchaseOrder::with(['customer','area'])
            ->whereDate('created_at',$today)
            ->where('status','in_progress')
            ->latest('created_at')
            ->get();

        $areas = Area::orderBy('name')
            ->pluck('name','id');
        
        $products = Product::where('active',true)
            ->orderBy('name')
            ->get(['id','name','base_price']);

        $previewPo = $this
            ->previewPoNumber();

        $cashAccounts = CashAccount::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        return view('adminapp.orders', compact('poDraft','poProg','customers','areas','products','previewPo', 'cashAccounts'));
    }

    public function ordersShow(PurchaseOrder $po)
    {
        $po->load(['customer','area','items.product', 'cashAccount', 'deliveryOrders']);
        return view('adminapp.order_show', compact('po'));
    }

    public function ordersEdit(PurchaseOrder $po)
    {
        $po->load(['customer','area','items.product', 'deliveryOrders']);

        if ($this->purchaseOrderIsLockedForEdit($po)) {
            return redirect()
                ->route('adminapp.orders.show', $po)
                ->with('error', 'PO yang sudah selesai produksi atau delivered tidak bisa diubah.');
        }

        $customers = Customer::query()
            ->where(function ($query) use ($po) {
                $query->where('active', true);

                if ($po->customer_id) {
                    $query->orWhere('id', $po->customer_id);
                }
            })
            ->orderBy('name')
            ->get();

        $areas = Area::orderBy('name')
            ->pluck('name','id');

        $productIds = $po->items->pluck('product_id')->filter()->all();

        $products = Product::query()
            ->where(function ($query) use ($productIds) {
                $query->where('active', true);

                if (! empty($productIds)) {
                    $query->orWhereIn('id', $productIds);
                }
            })
            ->orderBy('name')
            ->get(['id','name','base_price']);

        $cashAccounts = CashAccount::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        return view('adminapp.order_edit', compact('po','customers','areas','products', 'cashAccounts'));
    }

    public function ordersStore(Request $r)
    {
        $data = $r->validate([
            'customer_id'     => ['required', Rule::exists('customers', 'id')->where('active', true)],
            'recipient_name'  => ['required','string','max:100'],
            'shipping_address'=> ['required','string','max:255'],
            'area_id'         => ['required','exists:areas,id'],
            'delivery_date'   => ['required','date'],
            'delivery_time'   => ['nullable','date_format:H:i'],
            'discount_amount' => ['nullable','numeric','min:0'],
            'shipping_cost'   => ['nullable','numeric','min:0'],
            'payment_type'    => ['required','in:cash,receivable'],
            'cash_account_id' => ['nullable','exists:cash_accounts,id','required_if:payment_type,cash'],
            'receivable_days' => ['nullable','integer','min:1','max:365','required_if:payment_type,receivable'],
            'items'           => ['required','array','min:1'],
            'items.*.product_id' => ['required','exists:products,id'],
            'items.*.qty'        => ['required','integer','min:1'],
            'items.*.notes'      => ['nullable','string','max:255'],
        ]);

        $poNumber = $this->generatePoNumber();

        $po = PurchaseOrder::create([
            'po_number'       => $poNumber,
            'customer_id'     => $data['customer_id'],
            'recipient_name'  => $data['recipient_name'],
            'shipping_address'=> $data['shipping_address'],
            'area_id'         => $data['area_id'],
            'delivery_date'   => Carbon::parse($data['delivery_date'])->toDateString(),
            'delivery_time'   => $data['delivery_time'] ?? null,
            'discount_amount' => $data['discount_amount'] ?? 0,
            'shipping_cost'   => $data['shipping_cost'] ?? 0,
            'payment_type'    => $data['payment_type'],
            'cash_account_id' => ($data['payment_type'] ?? 'cash') === 'cash'
                ? ($data['cash_account_id'] ?? null)
                : null,
            'receivable_days' => $this->normalizeReceivableDays($data),
            'due_date'        => $this->calculatePaymentDueDate($data),
            'cash_received_at'=> null,
            'cash_received_by'=> null,
            'receivable_status' => $this->defaultReceivableStatus($data),
            'status'          => 'draft',
            'created_by'      => Auth::id(),
        ]);

        $totalQty    = 0;
        $totalAmount = 0;

        foreach ($data['items'] as $row) {
            $product = Product::find($row['product_id']);
            $price   = $product->base_price ?? 0;
            $qty     = (int) $row['qty'];
            $notes   = $row['notes'] ?? null;

            $subtotal = $price * $qty;
            $totalQty += $qty;
            $totalAmount += $subtotal;

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id'        => $product->id,
                'qty'               => $qty,
                'unit_price'        => $price,
                'raw_material_cost' => $product->raw_material_cost,
                'overhead_cost'     => $product->overhead_cost,
                'subtotal'          => $subtotal,
                'notes'             => $notes,
            ]);
        }

        // total akhir: (sum item - diskon) + ongkir
        $discount     = $data['discount_amount'] ?? 0;
        $shippingCost = $data['shipping_cost'] ?? 0;

        $po->total_qty    = $totalQty;
        $po->total_amount = max(0, $totalAmount - $discount + $shippingCost);
        $po->save();

        // 🔹 CATAT AUDIT LOG (CREATE)
        AuditLog::create([
            'user_id'           => Auth::id(),
            'entity'            => 'purchase_orders',   // bebas: 'purchase_order' / 'po'
            'entity_id'         => $po->id,
            'purchase_order_id' => $po->id,            // boleh dipakai juga, sekalian
            'action'            => 'created',
            'message'           => sprintf(
                'User %s telah membuat PO dengan ID %s pada %s',
                Auth::user()->name ?? 'Unknown',
                $po->po_number,
                now()->format('d-m-Y H:i')
            ),
            'before_json'       => null,
            'after_json'        => json_encode($po->toArray()),
            'ip_address'        => $r->ip(),
        ]);

        return redirect()
            ->route('adminapp.orders.index')
            ->with('success', "PO dengan ID {$po->po_number} (draft PO) berhasil dibuat.");
    }

    public function ordersUpdate(Request $r, PurchaseOrder $po)
    {
        if ($this->purchaseOrderIsLockedForEdit($po)) {
            return redirect()
                ->route('adminapp.orders.show', $po)
                ->with('error', 'PO yang sudah selesai produksi atau delivered tidak bisa diubah.');
        }

        $data = $r->validate([
            'customer_id'      => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail) use ($po) {
                    $isAllowedCustomer = Customer::query()
                        ->whereKey($value)
                        ->where(function ($query) use ($po) {
                            $query->where('active', true);

                            if ($po->customer_id) {
                                $query->orWhere('id', $po->customer_id);
                            }
                        })
                        ->exists();

                    if (! $isAllowedCustomer) {
                        $fail('Customer yang dipilih tidak valid.');
                    }
                },
            ],
            'recipient_name'   => ['required', 'string', 'max:100'],
            'shipping_address' => ['required', 'string', 'max:255'],
            'area_id'          => ['required', 'exists:areas,id'],
            'delivery_date'    => ['required', 'date'],
            'delivery_time'    => ['nullable', 'date_format:H:i'],
            'discount_amount'  => ['nullable', 'numeric', 'min:0'],
            'shipping_cost'    => ['nullable','numeric','min:0'],
            'payment_type'     => ['required','in:cash,receivable'],
            'cash_account_id'  => ['nullable','exists:cash_accounts,id','required_if:payment_type,cash'],
            'receivable_days'  => ['nullable','integer','min:1','max:365','required_if:payment_type,receivable'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'exists:products,id'],
            'items.*.qty'          => ['required', 'integer', 'min:1'],
            'items.*.notes'        => ['nullable', 'string', 'max:255'],
            'edit_reason'          => ['required', 'string', 'max:1000'],
        ]);

        $before = [
            'po' => $po->fresh(['customer', 'area', 'items.product', 'cashAccount'])?->toArray(),
        ];

        DB::transaction(function () use ($po, $data) {
            $paymentState = $this->resolveOrderPaymentStateForUpdate($po, $data);

            $po->update([
                'customer_id'      => $data['customer_id'],
                'recipient_name'   => $data['recipient_name'],
                'shipping_address' => $data['shipping_address'],
                'area_id'          => $data['area_id'],
                'delivery_date'    => \Carbon\Carbon::parse($data['delivery_date'])->toDateString(),
                'delivery_time'    => $data['delivery_time'] ?? null,
                'discount_amount'  => $data['discount_amount'] ?? 0,
                'shipping_cost'    => $data['shipping_cost'] ?? 0,
                'payment_type'     => $data['payment_type'],
                'cash_account_id'  => ($data['payment_type'] ?? 'cash') === 'cash'
                    ? ($data['cash_account_id'] ?? null)
                    : null,
                'receivable_days'  => $this->normalizeReceivableDays($data),
                'due_date'         => $this->calculatePaymentDueDate($data),
                'cash_received_at' => $paymentState['cash_received_at'],
                'cash_received_by' => $paymentState['cash_received_by'],
                'receivable_status' => $paymentState['receivable_status'],
            ]);

            // Snapshot harga & cost lama per produk supaya edit tidak menarik harga master terbaru
            // untuk produk yang memang sudah ada di PO ini.
            $existingByProduct = $po->items->groupBy('product_id');

            $po->items()->delete();

            $totalQty    = 0;
            $totalAmount = 0;

            foreach ($data['items'] as $row) {
                $product = Product::find($row['product_id']);
                $qty     = (int) $row['qty'];
                $notes   = $row['notes'] ?? null;

                $existing = $existingByProduct->get($product->id)?->first();
                $price = $existing
                    ? (float) $existing->unit_price
                    : (float) ($product->base_price ?? 0);
                $rawMaterialCost = $existing
                    ? $existing->raw_material_cost
                    : $product->raw_material_cost;
                $overheadCost = $existing
                    ? $existing->overhead_cost
                    : $product->overhead_cost;

                $subtotal = $price * $qty;
                $totalQty += $qty;
                $totalAmount += $subtotal;

                $po->items()->create([
                    'product_id'        => $product->id,
                    'qty'               => $qty,
                    'unit_price'        => $price,
                    'raw_material_cost' => $rawMaterialCost,
                    'overhead_cost'     => $overheadCost,
                    'subtotal'          => $subtotal,
                    'notes'             => $notes,
                ]);
            }

            $discount     = $data['discount_amount'] ?? 0;
            $shippingCost = $data['shipping_cost'] ?? 0;

            $po->total_qty    = $totalQty;
            $po->total_amount = max(0, $totalAmount - $discount + $shippingCost);
            $po->save();
        });

        $po->refresh()->load(['customer', 'area', 'items.product', 'cashAccount']);

        AuditLog::create([
            'user_id'           => Auth::id(),
            'entity'            => 'purchase_orders',
            'entity_id'         => $po->id,
            'purchase_order_id' => $po->id,
            'action'            => 'updated',
            'message'           => sprintf(
                'User %s telah mengubah PO %s pada %s. Alasan: %s',
                Auth::user()->name ?? 'Unknown',
                $po->po_number,
                now()->format('d-m-Y H:i'),
                $data['edit_reason']
            ),
            'before_json'       => $before,
            'after_json'        => [
                'po' => $po->toArray(),
                'edit_reason' => $data['edit_reason'],
            ],
            'ip_address'        => $r->ip(),
        ]);

        return redirect()
            ->route('adminapp.orders.show', $po->id)
            ->with('success', "PO dengan ID {$po->po_number} berhasil diperbarui dan tersinkron ke accounting.");
    }

    protected function resolveOrderPaymentStateForUpdate(PurchaseOrder $po, array $data): array
    {
        if ($po->status !== 'completed') {
            return [
                'cash_received_at' => null,
                'cash_received_by' => null,
                'receivable_status' => $this->defaultReceivableStatus($data),
            ];
        }

        if (($data['payment_type'] ?? 'cash') === 'cash') {
            return [
                'cash_received_at' => null,
                'cash_received_by' => null,
                'receivable_status' => null,
            ];
        }

        return [
            'cash_received_at' => $po->receivable_status === 'paid' ? ($po->cash_received_at ?? now()) : null,
            'cash_received_by' => $po->receivable_status === 'paid' ? ($po->cash_received_by ?? Auth::id()) : null,
            'receivable_status' => $po->receivable_status ?? $this->defaultReceivableStatus($data),
        ];
    }

    protected function purchaseOrderIsLockedForEdit(PurchaseOrder $po): bool
    {
        return $po->isCompleted() || $po->hasDeliveredDeliveryOrder();
    }

    public function ordersDestroy(Request $request, PurchaseOrder $po)
    {
        // 1) Snapshot sebelum dihapus (termasuk relasi)
        $po->load(['items', 'customer', 'area']);

        $before = [
            'po'    => $po->toArray(),
            'items' => $po->items->toArray(),
        ];

        $user = Auth::user();
        $now  = now()->format('d-m-Y H:i');

        // 2) Tulis audit log SAAT PO MASIH ADA
        AuditLog::create([
            'user_id'           => $user->id,
            'entity'            => 'purchase_orders',
            'entity_id'         => $po->id,        // ✅ WAJIB tidak null
            'purchase_order_id' => $po->id,        // ✅ valid terhadap FK (atau bisa kamu ganti null)
            'action'            => 'deleted',
            'message'           => sprintf(
                'User %s menghapus PO %s pada %s',
                $user->name ?? 'Unknown',
                $po->po_number,
                $now
            ),
            'before_json'       => $before,
            'after_json'        => null,
            'ip_address'        => $request->ip(),
        ]);

        // 3) Baru hapus PO
        $po->delete();

        // 4) Redirect dengan pesan yang benar
        // tentukan mau balik ke mana
        $redirect = $request->input('redirect', 'dashboard'); // default: orders

        if ($redirect === 'dashboard') {
            return redirect()
                ->route('adminapp.dashboard')
                ->with('success', "PO dengan ID {$po->po_number} sudah dihapus.");
        }

        return redirect()
            ->route('adminapp.orders.index')
            ->with('success', "PO dengan ID {$po->po_number} sudah dihapus.");
    }

    public function spkIndex()
    {
    // semua PO yang masih draft, urutkan berdasarkan tgl & jam kirim
        $poDraftToday = PurchaseOrder::with(['customer','area'])
            ->where('status', 'draft')
            ->orderBy('delivery_date')
            ->orderBy('delivery_time')  // kalau kolomnya nullable, ini aman
            ->get();

        return view('adminapp.spk', compact('poDraftToday'));
    }

    public function spkStore(Request $r)
    {
        $data = $r->validate([
            'po_ids'       => ['required','array','min:1'],
            'po_ids.*'     => ['integer','exists:purchase_orders,id'],
            'schedule_date'=> ['required','date'],
            'slot_type'    => ['required','in:fixed_03,fixed_07,fixed_11,custom'],
            'custom_time'  => ['nullable','date_format:H:i'],
        ]);

        // Tentukan jam produksi berdasarkan slot
        $time = match ($data['slot_type']) {
            'fixed_03' => '03:00',
            'fixed_07' => '07:00',
            'fixed_11' => '11:00',
            'custom'   => $data['custom_time'] ?? '03:00',
        };

        $scheduledAt = Carbon::parse($data['schedule_date'])
            ->setTimeFromTimeString($time);

        // Ambil PO yang dipilih
        $pos = PurchaseOrder::whereIn('id', $data['po_ids'])->get();

        // validasi schedule vs delivery
        $violations = [];
        foreach ($pos as $po) {
            $deliveryTime = $po->delivery_time ?: '23:59';
            $deliveryAt = Carbon::parse($po->delivery_date)
                ->setTimeFromTimeString($deliveryTime);

            if ($scheduledAt->gt($deliveryAt)) {
                $violations[] = $po->po_number;
            }
        }

        if (! empty($violations)) {
            return back()
                ->withErrors([
                    'schedule_date' => 'Tanggal/jam produksi tidak boleh lebih lambat dari tanggal/jam kirim untuk PO: '
                        . implode(', ', $violations),
                ])
                ->withInput();
        }

        $spk = Spk::create([
            'spk_code'           => $this->generateSpkCode(),
            'scheduled_at'       => $scheduledAt,
            'slot_type'          => $data['slot_type'],
            'responsible_user_id'=> auth::id(),
            'status'             => 'in_process',
            'created_by'         => auth::id(),
        ]);

        // KAITKAN SPK <-> PO via pivot
        $spk->purchaseOrders()->attach($data['po_ids']);

        foreach ($pos as $po) {
        if (Schema::hasColumn('purchase_orders','spk_id')) {
            $po->spk_id = $spk->id;
        }
        $po->status = 'in_progress';
        $po->save();
    }

    AuditLog::create([
        'user_id'           => Auth::id(),
        'entity'            => 'spk',
        'entity_id'         => $spk->id,
        'purchase_order_id' => null,
        'action'            => 'created',
        'message'           => sprintf(
            'User %s membuat SPK dengan ID %s pada %s untuk PO: %s',
            Auth::user()?->name ?? 'Unknown',
            $spk->spk_code,
            now()->format('d-m-Y H:i'),
            $pos->pluck('po_number')->implode(', ')
        ),
        'before_json'       => null,
        'after_json'        => null,
        'ip_address'        => $r->ip(),
    ]);

    return back()->with('success', "SPK dengan ID {$spk->spk_code} dibuat. PO terpilih masuk produksi (" . UiLabel::purchaseOrderStatus('in_progress') . ").");
    }

    protected function normalizeReceivableDays(array $data): ?int
    {
        if (($data['payment_type'] ?? 'cash') !== 'receivable') {
            return null;
        }

        return isset($data['receivable_days']) ? (int) $data['receivable_days'] : null;
    }

    protected function defaultReceivableStatus(array $data): ?string
    {
        if (($data['payment_type'] ?? 'cash') !== 'receivable') {
            return null;
        }

        return 'unpaid';
    }

    protected function calculatePaymentDueDate(array $data): ?string
    {
        if (($data['payment_type'] ?? 'cash') !== 'receivable') {
            return null;
        }

        $days = isset($data['receivable_days']) ? (int) $data['receivable_days'] : 0;
        if ($days <= 0) {
            return null;
        }

        return Carbon::parse($data['delivery_date'])
            ->addDays($days)
            ->toDateString();
    }

    protected function generatePoNumber(): string
    {
        $today = now()->format('Ymd');
        $prefix = "PO-{$today}-";

        // Cari po_number terakhir untuk hari ini
        $lastPo = PurchaseOrder::where('po_number', 'like', $prefix.'%')
            ->orderByDesc('po_number')
            ->first();

        if ($lastPo) {
            // Ambil 4 digit terakhir sebagai sequence number
            $lastSeq = (int) substr($lastPo->po_number, -4);
        } else {
            $lastSeq = 0;
        }

        $nextSeq = $lastSeq + 1;

        return $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
    }

    protected function previewPoNumber(): string
    {
        return $this->generatePoNumber();
    }

    protected function generateSpkCode(): string
    {
        $today = now()->format('Ymd');
        $seq = str_pad((Spk::whereDate('created_at', today())->count() + 1), 4, '0', STR_PAD_LEFT);
        return "SPK-{$today}-{$seq}";
    }

    // helper untuk DO code
    protected function generateDoCode(): string
    {
        $today = now()->format('Ymd');
        $seq = str_pad((DeliveryOrder::whereDate('created_at', today())->count() + 1), 4, '0', STR_PAD_LEFT);
        return "DO-{$today}-{$seq}";
    }

    /** ====== DELIVERY: INDEX ====== */
    public function deliveryIndex()
    {
        // PO yang sudah completed & BELUM punya DO
        $completedPO = PurchaseOrder::with(['customer','area'])
            ->where('status','completed')
            ->whereDoesntHave('deliveryOrders')
            ->latest('created_at')
            ->get();

        // dropdown area & driver
        $areas = Area::orderBy('name')->pluck('name','id');

        // ambil user role delivery (Spatie)
        $drivers = User::whereHas('roles', function ($q) {
                $q->where('name', 'delivery');
            })
            ->whereDoesntHave('roles', function ($q) {
                $q->where('name', 'superadmin');
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('adminapp.delivery', compact('completedPO','areas','drivers'));
    }

    /** ====== DELIVERY: STORE ====== */
    public function deliveryStore(\Illuminate\Http\Request $r)
    {
        $data = $r->validate([
            'po_ids'        => ['required','array','min:1'],
            'po_ids.*'      => ['integer','exists:purchase_orders,id'],
            'area_id'       => ['required','exists:areas,id'],
            'schedule_date' => ['required','date'],
            'schedule_time' => ['required','date_format:H:i'],
            'driver_user_id'=> ['required', Rule::exists('users', 'id')->where('is_active', true)],
        ]);

        // Ambil PO yang dipilih beserta relasi
        $pos = PurchaseOrder::with(['customer','area'])
            ->whereIn('id', $data['po_ids'])
            ->get();

    if ($pos->isEmpty()) {
            return back()
                ->withErrors(['po_ids' => 'Tidak ada PO yang valid dipilih.'])
                ->withInput();
        }

        $scheduledAt = Carbon::parse($data['schedule_date'])
            ->setTimeFromTimeString($data['schedule_time']);

        // Buat DO (status: ready)
        $do = DeliveryOrder::create([
            'do_code'        => $this->generateDoCode(),
            'area_id'        => $data['area_id'],
            'scheduled_at'   => $scheduledAt,
            'driver_user_id' => $data['driver_user_id'],
            'status'         => 'ready',
            'created_by'     => Auth::id(),
        ]);

        // *** LINK PO ⇄ DO via pivot ***
        $do->purchaseOrders()->syncWithoutDetaching($pos->pluck('id')->all());

        // AUDIT LOG per PO: PO dijadwalkan ke DO
        foreach ($pos as $po) {
            AuditLog::create([
                'user_id'           => Auth::id(),
                'entity'            => 'purchase_orders',
                'entity_id'         => $po->id,
                'purchase_order_id' => $po->id,
                'action'            => 'scheduled_for_delivery',
                'message'           => sprintf(
                    'PO %s dijadwalkan ke DO %s oleh %s pada %s',
                    $po->po_number,
                    $do->do_code,
                    Auth::user()->name ?? 'Unknown',
                    now()->format('d-m-Y H:i')
                ),
                'before_json'       => null,
                'after_json'        => null,
                'ip_address'        => $r->ip(),
            ]);
        }

        // (opsional) bisa juga tambahin 1 audit untuk entity = 'delivery_order'
        AuditLog::create([
            'user_id'           => Auth::id(),
            'entity'            => 'delivery_orders',
            'entity_id'         => $do->id,
            'purchase_order_id' => null,
            'action'            => 'created',
            'message'           => sprintf(
                'DO %s dibuat untuk %d PO oleh %s pada %s',
                $do->do_code,
                $pos->count(),
                Auth::user()->name ?? 'Unknown',
                now()->format('d-m-Y H:i')
            ),
            'before_json'       => null,
            'after_json'        => json_encode([
                'do'  => $do->toArray(),
                'pos' => $pos->pluck('po_number')->all(),
            ]),
            'ip_address'        => $r->ip(),
        ]);

        return redirect()
            ->route('adminapp.delivery.index')
            ->with('success', "DO dengan ID {$do->do_code} dibuat (status: " . UiLabel::deliveryStatus('ready') . ").");
    }

    public function ordersMissingCosts(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $productsMissingCosts = $this->fetchProductsMissingCosts($dateFrom, $dateTo);
        $productIds = $productsMissingCosts->pluck('id');

        $impactedOrders = $productIds->isEmpty()
            ? collect()
            : PurchaseOrder::query()
                ->with(['customer', 'items.product'])
                ->whereBetween('created_at', [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()])
                ->whereHas('items', function ($query) use ($productIds) {
                    $query->whereIn('product_id', $productIds);
                })
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

        return view('adminapp.reports.missing_costs', [
            'productsMissingCosts' => $productsMissingCosts,
            'impactedOrders' => $impactedOrders,
            'productIds' => $productIds,
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo'   => $dateTo->toDateString(),
        ]);
    }

    public function exportMissingCostsExcel(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $productsMissingCosts = $this->fetchProductsMissingCosts($dateFrom, $dateTo);

        $fileName = 'menu_tanpa_hpp_ohc_'
            . $dateFrom->format('Ymd') . '_' . $dateTo->format('Ymd')
            . '_' . now()->format('His') . '.xlsx';

        return Excel::download(
            new ProductsExport($productsMissingCosts),
            $fileName
        );
    }

    protected function fetchProductsMissingCosts(Carbon $dateFrom, Carbon $dateTo): \Illuminate\Database\Eloquent\Collection
    {
        $fromTs = $dateFrom->copy()->startOfDay();
        $toTs   = $dateTo->copy()->endOfDay();

        $products = Product::query()
            ->where(function ($query) {
                $query->whereNull('raw_material_cost')
                    ->orWhere('raw_material_cost', 0)
                    ->orWhereNull('overhead_cost')
                    ->orWhere('overhead_cost', 0);
            })
            ->whereExists(function ($query) use ($fromTs, $toTs) {
                $query->select(DB::raw(1))
                    ->from('purchase_order_items')
                    ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                    ->whereColumn('purchase_order_items.product_id', 'products.id')
                    ->whereBetween('purchase_orders.created_at', [$fromTs, $toTs]);
            })
            ->orderBy('name')
            ->get();

        $productIds = $products->pluck('id');

        $poItemCounts = $productIds->isEmpty()
            ? collect()
            : DB::table('purchase_order_items')
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                ->whereIn('purchase_order_items.product_id', $productIds)
                ->whereBetween('purchase_orders.created_at', [$fromTs, $toTs])
                ->groupBy('purchase_order_items.product_id')
                ->selectRaw('purchase_order_items.product_id as product_id, COUNT(*) as total')
                ->pluck('total', 'product_id');

        $products->each(function (Product $product) use ($poItemCounts) {
            $product->setAttribute('po_item_count', (int) ($poItemCounts[$product->id] ?? 0));
        });

        return new \Illuminate\Database\Eloquent\Collection(
            $products->sortByDesc('po_item_count')->values()->all()
        );
    }

    //  LAPORAN PO
    public function ordersReport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');
        $menuQuery = trim((string) $request->input('menu', ''));

        $ordersQuery = PurchaseOrder::with(['customer','area','items.product', 'deliveryOrders'])
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $ordersQuery->where('status', $status);
        }

        if ($menuQuery !== '') {
            $ordersQuery->whereHas('items.product', function ($query) use ($menuQuery) {
                $query->where('name', 'like', '%' . $menuQuery . '%');
            });
        }

        $orders = $ordersQuery->orderByDesc('created_at')->get();

        return view('adminapp.reports.orders', [
            'orders'      => $orders,
            'ordersCount' => $orders->count(),
            'dateFrom'    => $dateFrom->toDateString(),
            'dateTo'      => $dateTo->toDateString(),
            'status'      => $status,
            'menuQuery'   => $menuQuery,
        ]);
    }

    public function exportOrdersExcel(Request $request): StreamedResponse
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');
        $menuQuery = trim((string) $request->input('menu', ''));

        $ordersQuery = PurchaseOrder::with(['customer','area','items.product', 'deliveryOrders'])
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $ordersQuery->where('status', $status);
        }

        if ($menuQuery !== '') {
            $ordersQuery->whereHas('items.product', function ($query) use ($menuQuery) {
                $query->where('name', 'like', '%' . $menuQuery . '%');
            });
        }

        $orders = $ordersQuery->orderByDesc('created_at')->get();

        $fileName = 'laporan_po_' . $dateFrom->format('Ymd') . '_' . $dateTo->format('Ymd') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ];

        $callback = function () use ($orders) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['PO Number', 'Customer', 'Menu', 'Status', 'Created At']);

            foreach ($orders as $o) {
                $menuSummary = $o->items
                    ->map(function ($item) {
                        $name = $item->product->name ?? 'Produk';
                        $text = $name . ' x' . (int) $item->qty;
                        if (! empty($item->notes)) {
                            $text .= ' (' . $item->notes . ')';
                        }
                        return $text;
                    })
                    ->implode('; ');

                fputcsv($handle, [
                    $o->po_number,
                    optional($o->customer)->name,
                    $menuSummary,
                    UiLabel::purchaseOrderStatus($o->status),
                    optional($o->created_at)?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportOrdersPdf(Request $request)
    {
        // Untuk saat ini: pakai view print-friendly, bisa Save as PDF dari browser.
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');
        $menuQuery = trim((string) $request->input('menu', ''));

        $ordersQuery = PurchaseOrder::with(['customer','area','items.product', 'deliveryOrders'])
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $ordersQuery->where('status', $status);
        }

        if ($menuQuery !== '') {
            $ordersQuery->whereHas('items.product', function ($query) use ($menuQuery) {
                $query->where('name', 'like', '%' . $menuQuery . '%');
            });
        }

        $orders = $ordersQuery->orderByDesc('created_at')->get();

        return view('adminapp.reports.orders_pdf', [
            'orders'      => $orders,
            'dateFrom'    => $dateFrom->toDateString(),
            'dateTo'      => $dateTo->toDateString(),
            'status'      => $status,
        ]);
    }

    // LAPORAN PRODUKSI (SPK)
    public function productionReport(Request $request)
    {
        $reportData = $this->buildProductionReportData($request);

        return view('adminapp.reports.production', [
            ...$reportData,
            'pageLayout' => $reportData['viewMode'] === 'full'
                ? 'layouts.admin-report'
                : 'layouts.adminapp',
        ]);
    }

    public function exportProductionExcel(Request $request): StreamedResponse
    {
        $reportData = $this->buildProductionReportData($request);
        $spks = $reportData['spks'];
        $reportRows = $reportData['reportRows'];
        $dateFrom = Carbon::parse($reportData['dateFrom']);
        $dateTo = Carbon::parse($reportData['dateTo']);

        $fileName = 'laporan_produksi_' . $dateFrom->format('Ymd') . '_' . $dateTo->format('Ymd') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ];

        $callback = function () use ($spks, $reportRows) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['SPK Code', 'Scheduled At', 'Customer', 'Menu & Jumlah', 'Harga', 'Keterangan', 'Status']);

            foreach ($reportRows as $index => $reportRow) {
                $s = $spks[$index];

                fputcsv($handle, [
                    $s->spk_code,
                    optional($s->scheduled_at)?->format('Y-m-d H:i:s'),
                    $reportRow['customer_summary'],
                    $reportRow['menu_summary'],
                    $reportRow['price_summary'],
                    $reportRow['notes_summary'],
                    UiLabel::spkStatus($s->status),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportProductionPdf(Request $request)
    {
        $reportData = $this->buildProductionReportData($request);

        return view('adminapp.reports.production_pdf', [
            ...$reportData,
        ]);
    }

    protected function buildProductionReportData(Request $request): array
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->filled('status') ? (string) $request->input('status') : null;
        $viewMode = (string) $request->input('view_mode', 'summary');

        if (! in_array($viewMode, ['summary', 'full'], true)) {
            $viewMode = 'summary';
        }

        $spks = Spk::with([
                'purchaseOrders.customer:id,name',
                'purchaseOrders.items.product:id,name',
            ])
            ->whereBetween('scheduled_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('scheduled_at')
            ->get();

        $reportRows = $spks
            ->map(fn (Spk $spk) => $this->mapProductionReportRow($spk))
            ->values();

        return [
            'spks' => $spks,
            'reportRows' => $reportRows,
            'spkCount' => $spks->count(),
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo' => $dateTo->toDateString(),
            'status' => $status,
            'viewMode' => $viewMode,
        ];
    }

    protected function mapProductionReportRow(Spk $spk): array
    {
        $customers = $spk->purchaseOrders
            ->map(fn (PurchaseOrder $po) => $po->customer?->name ?? $po->recipient_name)
            ->filter()
            ->unique()
            ->values();

        $itemLines = $spk->purchaseOrders
            ->flatMap(function (PurchaseOrder $po) {
                return $po->items->map(function ($item) {
                    return [
                        'product_name' => $item->product->name ?? 'Produk',
                        'qty' => (int) $item->qty,
                        'amount' => (float) ($item->subtotal ?? 0),
                        'notes' => $item->notes,
                    ];
                });
            })
            ->groupBy('product_name')
            ->map(function ($group, $productName) {
                $qty = (int) $group->sum('qty');
                $amount = (float) $group->sum('amount');
                $notes = $group->pluck('notes')->filter()->unique()->values();

                return [
                    'product_name' => $productName,
                    'qty' => $qty,
                    'amount' => $amount,
                    'notes' => $notes,
                    'menu_text' => $productName . ' x' . number_format($qty, 0, ',', '.'),
                    'price_text' => 'Rp ' . number_format($amount, 0, ',', '.'),
                ];
            })
            ->values();

        $notesLines = collect();

        if (filled($spk->notes)) {
            $notesLines->push('SPK: ' . $spk->notes);
        }

        $itemLines->each(function (array $line) use ($notesLines) {
            foreach ($line['notes'] as $note) {
                $notesLines->push($line['product_name'] . ': ' . $note);
            }
        });

        $totalAmount = (float) $itemLines->sum('amount');

        return [
            'customer_lines' => $customers,
            'customer_summary' => $customers->isNotEmpty() ? $customers->implode('; ') : '-',
            'item_lines' => $itemLines,
            'menu_summary' => $itemLines->isNotEmpty() ? $itemLines->pluck('menu_text')->implode('; ') : '-',
            'price_summary' => $itemLines->isNotEmpty()
                ? $itemLines->pluck('price_text')->implode('; ') . ' | Total Rp ' . number_format($totalAmount, 0, ',', '.')
                : '-',
            'notes_lines' => $notesLines->filter()->unique()->values(),
            'notes_summary' => $notesLines->filter()->unique()->isNotEmpty()
                ? $notesLines->filter()->unique()->implode('; ')
                : '-',
            'total_amount' => $totalAmount,
        ];
    }

    // LAPORAN DELIVERY
    public function deliveryReport(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $doQuery = DeliveryOrder::with(['area','driver'])
            ->whereBetween('scheduled_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $doQuery->where('status', $status);
        }

        $dos = $doQuery->orderByDesc('scheduled_at')->get();

        return view('adminapp.reports.delivery', [
            'dos'      => $dos,
            'doCount'  => $dos->count(),
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo'   => $dateTo->toDateString(),
            'status'   => $status,
        ]);
    }

    public function exportDeliveryExcel(Request $request): StreamedResponse
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $doQuery = DeliveryOrder::with(['area','driver'])
            ->whereBetween('scheduled_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $doQuery->where('status', $status);
        }

        $dos = $doQuery->orderByDesc('scheduled_at')->get();

        $fileName = 'laporan_delivery_' . $dateFrom->format('Ymd') . '_' . $dateTo->format('Ymd') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ];

        $callback = function () use ($dos) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['DO Code', 'Area', 'Driver', 'Recipient', 'Status', 'Scheduled At']);

            foreach ($dos as $d) {
                fputcsv($handle, [
                    $d->do_code,
                    optional($d->area)->name,
                    optional($d->driver)->name,
                    $d->recipient_name,
                    UiLabel::deliveryStatus($d->status),
                    optional($d->scheduled_at)?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportDeliveryPdf(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);
        $status = $request->input('status');

        $doQuery = DeliveryOrder::with(['area','driver'])
            ->whereBetween('scheduled_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($status) {
            $doQuery->where('status', $status);
        }

        $dos = $doQuery->orderByDesc('scheduled_at')->get();

        return view('adminapp.reports.delivery_pdf', [
            'dos'      => $dos,
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo'   => $dateTo->toDateString(),
            'status'   => $status,
        ]);
    }

    // AUDIT LOGS
    public function auditIndex(Request $request)
    {
        // Pakai helper tanggal yang sudah ada
        [$dateFrom, $dateTo] = $this->parseDateRange($request);

        $userId = $request->input('user_id');
        $entity = $request->input('entity');
        $action = $request->input('action');

        $logsQuery = AuditLog::with('user')
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($userId) {
            $logsQuery->where('user_id', $userId);
        }

        if ($entity) {
            $logsQuery->where('entity', $entity);
        }

        if ($action) {
            $logsQuery->where('action', $action);
        }

        $logs = $logsQuery
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();
    
        // Untuk dropdown filter
        $users = User::whereDoesntHave('roles', function ($q) {
        $q->where('name', 'superadmin');
        })
        ->where('is_active', true)
        ->orderBy('name')
        ->get();

        $availableEntities = AuditLog::select('entity')
            ->distinct()
            ->pluck('entity')
            ->filter()
            ->values();

        $availableActions = AuditLog::select('action')
            ->distinct()
            ->pluck('action')
            ->filter()
            ->values();

        return view('adminapp.audit.index', [
            'logs'             => $logs,
            'users'            => $users,
            'availableEntities'=> $availableEntities,
            'availableActions' => $availableActions,
            'dateFrom'         => $dateFrom->toDateString(),
            'dateTo'           => $dateTo->toDateString(),
            'userId'           => $userId,
            'entity'           => $entity,
            'action'           => $action,
        ]);
    }

    // LAPORAN BEST SELLER PER CUSTOMER
    public function bestSellerReport(Request $request)
    {
        // Pakai helper tanggal yang sudah ada
        [$dateFrom, $dateTo] = $this->parseDateRange($request);

        // Customer yang dipilih (per customer)
        $customerId = $request->input('customer_id');

        // Top N fleksibel, tapi dibatasi 1–10
        $limitInput = (int) $request->input('limit', 3);
        $limit = min(max($limitInput, 1), 10);

        // Dropdown customer
        $customers = Customer::where('active', true)
            ->orderBy('name')
            ->get(['id','name']);

        $results = collect();
        $selectedCustomer = null;

        if ($customerId) {
            $selectedCustomer = $customers->firstWhere('id', (int) $customerId);

            $results = PurchaseOrderItem::query()
                ->selectRaw("
                    products.id   as product_id,
                    products.name as product_name,
                    SUM(purchase_order_items.qty)      as total_qty,
                    COUNT(purchase_order_items.id)     as total_orders,
                    SUM(purchase_order_items.subtotal) as total_amount
                ")
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                ->join('products', 'products.id', '=', 'purchase_order_items.product_id')
                ->where('purchase_orders.customer_id', $customerId)
                ->where('purchase_orders.status', 'completed') // cuma PO completed
                ->whereBetween('purchase_orders.created_at', [
                    $dateFrom->copy()->startOfDay(),
                    $dateTo->copy()->endOfDay(),
                ])
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('total_qty')   // ranking utama: qty terbanyak
                ->limit($limit)
                ->get();
        }

        return view('adminapp.reports.bestseller', [
            'customers'        => $customers,
            'selectedCustomer' => $selectedCustomer,
            'results'          => $results,
            'dateFrom'         => $dateFrom->toDateString(),
            'dateTo'           => $dateTo->toDateString(),
            'limit'            => $limit,
            'customerId'       => $customerId,
        ]);
    }

    public function exportBestSellerExcel(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);

        $customerId = $request->input('customer_id');
        $limitInput = (int) $request->input('limit', 3);
        $limit = min(max($limitInput, 1), 10);

        if (! $customerId) {
            // Kalau customer belum dipilih, balik ke halaman laporan dengan pesan
            return redirect()
                ->route('adminapp.reports.bestseller', $request->query())
                ->with('error', 'Silakan pilih customer terlebih dahulu sebelum export.');
        }

        $customer = Customer::find($customerId);

        $results = PurchaseOrderItem::query()
            ->selectRaw("
                products.id   as product_id,
                products.name as product_name,
                SUM(purchase_order_items.qty)      as total_qty,
                COUNT(purchase_order_items.id)     as total_orders,
                SUM(purchase_order_items.subtotal) as total_amount
            ")
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->join('products', 'products.id', '=', 'purchase_order_items.product_id')
            ->where('purchase_orders.customer_id', $customerId)
            ->where('purchase_orders.status', 'completed')
            ->whereBetween('purchase_orders.created_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        $fileName = 'best_seller_'
            . ($customer?->name ? str_replace(' ', '_', strtolower($customer->name)) : 'customer')
            . '_' . $dateFrom->format('Ymd') . '_' . $dateTo->format('Ymd') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ];

        $callback = function () use ($results, $customer, $dateFrom, $dateTo) {
            $handle = fopen('php://output', 'w');

            // Header informasi di atas (opsional)
            fputcsv($handle, ['Laporan Best Seller per Customer']);
            fputcsv($handle, ['Customer', $customer?->name ?? '-']);
            fputcsv($handle, ['Periode',
                $dateFrom->format('Y-m-d'),
                's/d',
                $dateTo->format('Y-m-d'),
            ]);
            fputcsv($handle, []); // baris kosong

            // Header kolom
            fputcsv($handle, ['#', 'Produk', 'Total Qty', 'Frekuensi Order', 'Total Omzet']);

            $grandQty   = 0;
            $grandFreq  = 0;
            $grandTotal = 0;
            $no         = 1;

            foreach ($results as $row) {
                $grandQty   += $row->total_qty;
                $grandFreq  += $row->total_orders;
                $grandTotal += $row->total_amount;

                fputcsv($handle, [
                    $no++,
                    $row->product_name,
                    $row->total_qty,
                    $row->total_orders,
                    $row->total_amount,
                ]);
            }

            // Total di bawah
            fputcsv($handle, []);
            fputcsv($handle, ['TOTAL', '', $grandQty, $grandFreq, $grandTotal]);

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportBestSellerPdf(Request $request)
    {
        [$dateFrom, $dateTo] = $this->parseDateRange($request);

        $customerId = $request->input('customer_id');
        $limitInput = (int) $request->input('limit', 3);
        $limit = min(max($limitInput, 1), 10);

        if (! $customerId) {
            return redirect()
                ->route('adminapp.reports.bestseller', $request->query())
                ->with('error', 'Silakan pilih customer terlebih dahulu sebelum export.');
        }

        $customer = Customer::find($customerId);

        $results = PurchaseOrderItem::query()
            ->selectRaw("
                products.id   as product_id,
                products.name as product_name,
                SUM(purchase_order_items.qty)      as total_qty,
                COUNT(purchase_order_items.id)     as total_orders,
                SUM(purchase_order_items.subtotal) as total_amount
            ")
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->join('products', 'products.id', '=', 'purchase_order_items.product_id')
            ->where('purchase_orders.customer_id', $customerId)
            ->where('purchase_orders.status', 'completed')
            ->whereBetween('purchase_orders.created_at', [
                $dateFrom->copy()->startOfDay(),
                $dateTo->copy()->endOfDay(),
            ])
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        return view('adminapp.reports.bestseller_pdf', [
            'customer'  => $customer,
            'results'   => $results,
            'dateFrom'  => $dateFrom->toDateString(),
            'dateTo'    => $dateTo->toDateString(),
            'limit'     => $limit,
        ]);
    }

    // Utility parse range tanggal dari request, default: hari ini
    protected function parseDateRange(Request $request): array
    {
        $from = $request->input('date_from');
        $to   = $request->input('date_to');

        try {
            $dateFrom = $from ? Carbon::parse($from) : Carbon::today();
        } catch (\Exception $e) {
            $dateFrom = Carbon::today();
        }

        try {
            $dateTo = $to ? Carbon::parse($to) : Carbon::today();
        } catch (\Exception $e) {
            $dateTo = Carbon::today();
        }

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }
}
