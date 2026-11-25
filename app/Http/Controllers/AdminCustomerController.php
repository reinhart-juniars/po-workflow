<?php

namespace App\Http\Controllers;

use App\Exports\CustomersExport;
use App\Models\Area;
use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Services\CustomerInsightService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AdminCustomerController extends Controller
{
    public function index(Request $request, CustomerInsightService $insights)
    {
        [$q, $status] = $this->listFilters($request);

        $customers = $this->filteredQuery($insights, $q, $status)
            ->paginate(20)
            ->withQueryString();

        $statusCounts = collect(CustomerInsightService::statusOptions())
            ->map(fn ($label, $key) => $insights->filterByStatus(Customer::query()->external(), $key)->count());

        return view('adminapp.customers.index', [
            'customers' => $customers,
            'q' => $q,
            'status' => $status,
            'statusCounts' => $statusCounts,
            'insights' => $insights,
        ]);
    }

    /**
     * Export Excel untuk follow up tim marketing. Mengikuti tab status &
     * pencarian yang sedang dibuka, jadi "Lama tidak order" → hanya mereka.
     */
    public function export(Request $request, CustomerInsightService $insights)
    {
        [$q, $status] = $this->listFilters($request);

        $customers = $insights->withLifetimeAggregates($this->filteredQuery($insights, $q, $status))
            ->with('area:id,name')
            ->get();

        $fileName = 'customer_'.($status ?? 'semua').'_'.now()->format('Ymd_His').'.xlsx';

        return Excel::download(new CustomersExport($customers, $insights), $fileName);
    }

    /**
     * Filter daftar dari query string. Status di luar daftar diabaikan (= Semua).
     *
     * @return array{0: string, 1: ?string}
     */
    private function listFilters(Request $request): array
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        // Tab status CRM: semua / aktif / baru / lama tidak order / belum pernah order.
        $status = array_key_exists((string) $request->query('status'), CustomerInsightService::statusOptions())
            ? (string) $request->query('status')
            : null;

        return [$q, $status];
    }

    /** Query daftar customer (non internal) + agregat, dipakai halaman dan export. */
    private function filteredQuery(CustomerInsightService $insights, string $q, ?string $status): Builder
    {
        return $insights->withListAggregates(Customer::query()->external())
            ->when($status, fn ($query) => $insights->filterByStatus($query, $status))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('address', 'like', $like);
                });
            })
            ->orderBy('name');
    }

    /** Profil customer (mini CRM): ringkasan order, omzet bulanan, menu favorit, riwayat PO. */
    public function show(Customer $customer, CustomerInsightService $insights)
    {
        $customer->load('area');

        return view('adminapp.customers.show', [
            'customer' => $customer,
            'profile' => $insights->profile($customer),
            'monthly' => $insights->monthlyRevenue($customer),
            'favorites' => $insights->favoriteMenus($customer),
            'orders' => PurchaseOrder::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('delivery_date')
                ->orderByDesc('id')
                ->paginate(15, ['id', 'po_number', 'delivery_date', 'status', 'payment_type', 'receivable_status', 'total_amount', 'total_qty'])
                ->withQueryString(),
        ]);
    }

    public function create()
    {
        $customer = new Customer;
        $areas = \App\Models\Area::orderBy('name')->pluck('name', 'id'); // kalau ada

        return view('adminapp.customers.form', [
            'mode' => 'create',
            'customer' => $customer,
            'areas' => $areas,

        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'active' => ['nullable', 'boolean'],
            'is_lapak' => ['nullable', 'boolean'],
        ]);

        $data['active'] = $request->boolean('active');
        $data['is_lapak'] = $request->boolean('is_lapak');

        Customer::create($data);

        return redirect()
            ->route('adminapp.customers.index')
            ->with('status', 'Customer berhasil ditambahkan.');
    }

    public function edit(Customer $customer)
    {
        $areas = Area::orderBy('name')->pluck('name', 'id');

        return view('adminapp.customers.form', [
            'mode' => 'edit',
            'customer' => $customer,
            'areas' => $areas,
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'active' => ['nullable', 'boolean'],
            'is_lapak' => ['nullable', 'boolean'],
        ]);

        $data['active'] = $request->boolean('active');
        $data['is_lapak'] = $request->boolean('is_lapak');

        $customer->update($data);

        return redirect()
            ->route('adminapp.customers.index')
            ->with('status', 'Customer berhasil diperbarui.');
    }
}
