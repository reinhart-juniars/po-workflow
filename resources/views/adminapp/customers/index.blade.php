@extends('layouts.adminapp')

@section('title', 'Master Customer')

@section('content')
    <div class="page-toolbar">
        <div>
            <h1 class="section-title">Master Customer</h1>
            <p class="section-subtitle">Kelola data pelanggan untuk pemetaan pengiriman dan pembuatan PO.</p>
        </div>
        <a href="{{ route('adminapp.customers.create') }}" class="btn-primary">+ Tambah Customer Baru</a>
    </div>

    @if (session('status'))
        <div class="flash-success mt-4">{{ session('status') }}</div>
    @endif

    <section class="table-shell mt-4">
        <x-table-toolbar title="Daftar Customer" :action="route('adminapp.customers.index')"
            :meta="number_format($customers->total(), 0, ',', '.').' customer'.(filled($q ?? null) ? ' cocok' : '')"
            search="q" :search-value="$q ?? ''" search-placeholder="Cari nama / telepon / alamat..." live />
        <div class="data-table-wrap">
            <table class="data-table" id="js-customers-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>No Telepon</th>
                        <th>Alamat</th>
                        <th>Kategori</th>
                        <th>Aktif</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($customers as $customer)
                        <tr data-search-name="{{ strtolower($customer->name) }}">
                            <td class="font-semibold text-slate-800">{{ $customer->name }}</td>
                            <td>{{ $customer->phone ?? '-' }}</td>
                            <td class="max-w-sm truncate" title="{{ $customer->address }}">{{ $customer->address ?? '-' }}
                            </td>
                            <td>
                                @if ($customer->is_lapak)
                                    <span class="chip bg-amber-100 text-amber-800">Lapak</span>
                                @else
                                    <span class="chip bg-slate-100 text-slate-600">Non Lapak</span>
                                @endif
                            </td>
                            <td>
                                @if ($customer->active)
                                    <span class="chip bg-emerald-100 text-emerald-700">Aktif</span>
                                @else
                                    <span class="chip bg-slate-200 text-slate-700">Nonaktif</span>
                                @endif
                            </td>
                            <td>
                                <x-row-action kind="edit" :href="route('adminapp.customers.edit', $customer)" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-sm text-slate-500">
                                @if (!empty($q))
                                    Tidak ada customer yang cocok dengan "<strong>{{ $q }}</strong>".
                                @else
                                    Belum ada data customer.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if (method_exists($customers, 'links'))
        <div class="mt-4">{{ $customers->links() }}</div>
    @endif

@endsection
