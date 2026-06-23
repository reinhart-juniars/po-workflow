@extends('layouts.superadmin', ['title' => 'Backup & Restore'])

@section('content')
<section class="dashboard-hero">
  <h1 class="dashboard-hero-title">Backup &amp; Restore Database</h1>
  <p class="dashboard-hero-subtitle">
    Unduh snapshot database untuk dianalisa di local. Restore tersedia hanya di environment non-production sebagai pengaman.
  </p>
</section>

<section class="stats-grid mt-4">
  <article class="stat-card">
    <p class="stat-label">Database</p>
    <p class="stat-value text-brand-600">{{ $database }}</p>
    <p class="stat-meta">Driver: {{ strtoupper($driver) }}</p>
  </article>
  <article class="stat-card">
    <p class="stat-label">Environment</p>
    <p class="stat-value {{ $environment === 'production' ? 'text-rose-600' : 'text-emerald-600' }}">
      {{ strtoupper($environment) }}
    </p>
    <p class="stat-meta">
      {{ $environment === 'production' ? 'Restore dinonaktifkan' : 'Restore tersedia' }}
    </p>
  </article>
</section>

<section class="mt-4">
  <article class="table-shell">
    <div class="table-head">Backup Database</div>
    <div class="space-y-3 p-4 text-sm text-slate-600">
      <p>
        Klik tombol di bawah untuk men-generate file <code>.sql.gz</code> berisi struktur tabel + seluruh data.
        File akan langsung di-stream ke browser (tidak disimpan di server).
      </p>
      <p class="text-xs text-slate-500">
        Catatan: backup berjalan synchronous. Untuk database besar, biarkan tab terbuka sampai download selesai.
      </p>
      <div>
        <a href="{{ route('superadmin.backup.download') }}" class="btn-primary">
          Download Backup (.sql.gz)
        </a>
      </div>
    </div>
  </article>
</section>

@if ($restoreEnabled)
<section class="mt-4">
  <article class="table-shell">
    <div class="table-head">Restore Database</div>
    <form method="POST" action="{{ route('superadmin.backup.restore') }}" enctype="multipart/form-data" class="space-y-3 p-4 text-sm text-slate-600">
      @csrf
      <p class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-rose-800">
        <strong>Peringatan:</strong> Restore akan <em>menimpa</em> data yang ada di database
        <strong>{{ $database }}</strong>. Pastikan ini bukan database production.
      </p>

      <div>
        <label class="form-label" for="sql_file">File Backup (.sql atau .sql.gz)</label>
        <input id="sql_file" name="sql_file" type="file" accept=".sql,.gz" required>
        <p class="field-help">Maksimal 500 MB.</p>
        @error('sql_file')
          <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label class="form-label" for="confirm">Ketik <code>RESTORE</code> untuk konfirmasi</label>
        <input id="confirm" name="confirm" type="text" placeholder="RESTORE" required autocomplete="off">
        @error('confirm')
          <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <button type="submit" class="btn-danger"
          onclick="return confirm('Yakin restore? Data di database {{ $database }} akan ditimpa.');">
          Jalankan Restore
        </button>
      </div>
    </form>
  </article>
</section>
@else
<section class="mt-4">
  <article class="table-shell">
    <div class="table-head">Restore Database</div>
    <div class="space-y-2 p-4 text-sm text-slate-600">
      <p>
        Restore via UI dinonaktifkan karena environment saat ini adalah <strong>production</strong>.
      </p>
      <p>
        Untuk analisa di local: download file backup di atas, lalu import di mesin lo dengan:
      </p>
      <pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">gunzip -c {{ $database }}_backup_YYYYMMDD_HHMMSS.sql.gz | mysql -u root -p {{ $database }}_local</pre>
    </div>
  </article>
</section>
@endif
@endsection
