{{-- Saran nama dari Master Supplier untuk input supplier teks bebas.
     Nama yang cocok otomatis ditautkan ke master (App\Models\Concerns\LinksSupplier).
     $supplierNames diisi view composer di AppServiceProvider. --}}
<datalist id="supplier-options">
  @foreach ($supplierNames as $supplierOption)
    <option value="{{ $supplierOption }}"></option>
  @endforeach
</datalist>
