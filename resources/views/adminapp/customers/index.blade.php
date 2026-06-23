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
        <div class="table-head">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <span>Daftar Customer</span>
                <form method="GET" action="{{ route('adminapp.customers.index') }}"
                      class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                    <input type="search" name="q" id="js-customers-search" class="form-control sm:w-64"
                        value="{{ $q ?? '' }}"
                        placeholder="Cari nama / telepon / alamat..." autocomplete="off">
                    @if (!empty($q))
                        <a href="{{ route('adminapp.customers.index') }}"
                           class="text-xs font-semibold text-slate-500 hover:text-slate-700">Reset</a>
                    @endif
                    <span class="text-xs font-medium normal-case tracking-normal text-slate-500">
                        {{ $customers->total() }} customer
                        @if (!empty($q)) cocok untuk "<strong>{{ $q }}</strong>" @else total @endif.
                    </span>
                </form>
            </div>
        </div>
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
                                <a href="{{ route('adminapp.customers.edit', $customer) }}"
                                    class="text-sm font-semibold text-brand-600 hover:text-brand-500">
                                    Edit
                                </a>
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

    <script>
        (() => {
            const input = document.getElementById('js-customers-search');
            if (!input) return;
            const form = input.closest('form');
            if (!form) return;

            let timer = null;
            input.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => form.submit(), 400);
            });
        })();
    </script>
@endsection
