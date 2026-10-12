@extends('layouts.adminapp')

@section('title', 'Customer')

{{--
  Daftar customer (mini CRM). Kolom order terakhir & omzet 90 hari dihitung
  dari PO completed (CustomerInsightService). Tab status: siapa yang aktif,
  siapa yang lama tidak order -- daftar kerja untuk follow up.
--}}
@php
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $statusLabels = \App\Services\CustomerInsightService::statusOptions();
    $statusBadge = [
        'baru' => 'badge-soft-brand',
        'aktif' => 'badge-soft-emerald',
        'lama' => 'badge-soft-amber',
        'belum' => 'badge-soft-slate',
    ];
    $tabs = ['' => 'Semua'] + $statusLabels;
@endphp

@section('content')
    <div class="page-toolbar">
        <div>
            <h1>Customer</h1>
            <p class="section-subtitle">Data pelanggan beserta riwayat ordernya. Buka profil untuk melihat omzet, menu favorit, dan riwayat PO.</p>
        </div>
        <div class="toolbar-actions">
            <a href="{{ route('adminapp.customers.create') }}" class="btn-primary">+ Tambah Customer</a>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-success mt-4">{{ session('status') }}</div>
    @endif

    <nav class="tab-pills mt-6" aria-label="Status customer">
        @foreach ($tabs as $key => $label)
            @php $active = ($status ?? '') === $key; @endphp
            <a href="{{ route('adminapp.customers.index', array_filter(['status' => $key ?: null, 'q' => $q ?: null])) }}"
               class="tab-pill {{ $active ? 'is-active' : '' }}" @if ($active) aria-current="page" @endif>
                {{ $label }}
                @if ($key !== '' && ($statusCounts[$key] ?? 0) > 0)
                    <span class="tab-pill-count">{{ number_format($statusCounts[$key], 0, ',', '.') }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    <section class="table-shell mt-4">
        <x-table-toolbar :title="$tabs[$status ?? '']" :action="route('adminapp.customers.index')"
            :meta="number_format($customers->total(), 0, ',', '.').' customer'.(filled($q ?? null) ? ' cocok' : '')"
            search="q" :search-value="$q ?? ''" search-placeholder="Cari nama / telepon / alamat..." :keep="array_filter(['status' => $status])" live />
        <div class="data-table-wrap">
            <table class="data-table" id="js-customers-table">
                <thead>
                    <tr>
                        <th class="min-w-[220px]">Nama</th>
                        <th>Kontak</th>
                        <th class="whitespace-nowrap">Order terakhir</th>
                        <th class="whitespace-nowrap text-right">PO {{ \App\Services\CustomerInsightService::RECENT_DAYS }} hr</th>
                        <th class="whitespace-nowrap text-right">Omzet {{ \App\Services\CustomerInsightService::RECENT_DAYS }} hr</th>
                        <th>Status</th>
                        <th><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        @php
                            $crm = $insights->status($customer->first_order_date, $customer->last_order_date);
                            $last = $customer->last_order_date ? \Carbon\Carbon::parse($customer->last_order_date) : null;
                        @endphp
                        <tr data-search-name="{{ strtolower($customer->name) }}">
                            <td>
                                <a href="{{ route('adminapp.customers.show', $customer) }}" class="font-semibold text-slate-950 hover:text-brand-600 hover:underline">{{ $customer->name }}</a>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @if ($customer->is_lapak)
                                        <span class="badge-soft-amber">Lapak</span>
                                    @endif
                                    @unless ($customer->active)
                                        <span class="badge-soft-slate">Nonaktif</span>
                                    @endunless
                                </div>
                            </td>
                            <td class="max-w-[260px] text-slate-600">
                                @if ($customer->phone)
                                    <div class="whitespace-nowrap">{{ $customer->phone }}</div>
                                @endif
                                <div class="truncate text-xs text-slate-500" title="{{ $customer->address }}">{{ $customer->address ?: '–' }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($last)
                                    {{ $last->translatedFormat('d M Y') }}
                                    <div class="text-xs text-slate-500">{{ (int) $last->diffInDays(today()) }} hari lalu</div>
                                @else
                                    <span class="text-slate-400">–</span>
                                @endif
                            </td>
                            <td class="text-right">{{ number_format((int) $customer->recent_po_count, 0, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap">{{ $customer->recent_revenue ? $rp($customer->recent_revenue) : '–' }}</td>
                            <td><span class="{{ $statusBadge[$crm] }}">{{ $statusLabels[$crm] }}</span></td>
                            <td class="text-right">
                                {{-- Profil dibuka lewat nama; di sini hanya ubah data. --}}
                                <x-row-action kind="edit" :href="route('adminapp.customers.edit', $customer)" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-10 text-center text-sm text-slate-500">
                                @if (!empty($q))
                                    Tidak ada customer yang cocok dengan "<strong>{{ $q }}</strong>".
                                @else
                                    Belum ada customer di sini.
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
