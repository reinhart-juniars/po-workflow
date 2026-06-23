<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Area; // kalau kamu punya tabel area
use Illuminate\Http\Request;

class AdminCustomerController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . $q . '%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('address', 'like', $like);
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('adminapp.customers.index', [
            'customers' => $customers,
            'q'         => $q,
        ]);
    }

    public function create()
    {
        $customer = new Customer();
        $areas    = \App\Models\Area::orderBy('name')->pluck('name', 'id'); // kalau ada

        return view('adminapp.customers.form', [
            'mode'     => 'create',
            'customer' => $customer,
            'areas'    => $areas,
            
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'active'  => ['nullable', 'boolean'],
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
            'mode'     => 'edit',
            'customer' => $customer,
            'areas'    => $areas,
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'active'  => ['nullable', 'boolean'],
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
