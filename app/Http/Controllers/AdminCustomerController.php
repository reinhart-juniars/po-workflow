<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Services\CustomerInsightService;
use Illuminate\Http\Request;

class AdminCustomerController extends Controller
{
    public function index(Request $request, CustomerInsightService $insights)
    {
        $q = trim((string) $request->query('q', ''));
        // Tab status CRM: semua / aktif / baru / lama tidak order / belum pernah order.
        $status = array_key_exists((string) $request->query('status'), CustomerInsightService::statusOptions())
            ? (string) $request->query('status')
            : null;

        $customers = $insights->withListAggregates(Customer::query())
            ->when($status, fn ($query) => $insights->filterByStatus($query, $status))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('address', 'like', $like);
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $statusCounts = collect(CustomerInsightService::statusOptions())
            ->map(fn ($label, $key) => $insights->filterByStatus(Customer::query(), $key)->count());

        return view('adminapp.customers.index', [
            'customers' => $customers,
            'q' => $q,
            'status' => $status,
            'statusCounts' => $statusCounts,
            'insights' => $insights,
        ]);
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
