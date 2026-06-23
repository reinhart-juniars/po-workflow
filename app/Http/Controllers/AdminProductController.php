<?php

namespace App\Http\Controllers;

use App\Exports\ProductsExport;
use App\Imports\ProductsImport;
use App\Models\Product;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AdminProductController extends Controller
{
    private const IMPORT_PREVIEW_SESSION_KEY = 'products_import_preview';

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->withMax('priceHistories as price_last_changed_at', 'effective_from')
            ->withCount('priceHistories')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . $q . '%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like);
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
        $importPreview = session(self::IMPORT_PREVIEW_SESSION_KEY);

        return view('adminapp.products.index', compact('products', 'importPreview', 'q'));
    }

    public function create()
    {
        $product = new Product();

        return view('adminapp.products.form', [
            'product' => $product,
            'mode'    => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedPayload($request);
        $data['sku'] = Product::generateUniqueSku($data['name']);
        $data['active'] = $request->boolean('active');
        $data['is_3s'] = $request->boolean('is_3s');

        Product::create($data);

        return redirect()
            ->route('adminapp.products.index')
            ->with('status', 'Menu berhasil ditambahkan.');
    }

    public function edit(Product $product)
    {
        $product->load(['priceHistories.changedBy']);

        return view('adminapp.products.form', [
            'product' => $product,
            'mode'    => 'edit',
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validatedPayload($request);
        $data['sku'] = $product->sku ?: Product::generateUniqueSku($data['name'], $product->id);
        $data['active'] = $request->boolean('active');
        $data['is_3s'] = $request->boolean('is_3s');

        $product->update($data);

        return redirect()
            ->route('adminapp.products.index')
            ->with('status', 'Menu berhasil diperbarui.');
    }

    public function exportExcel()
    {
        $fileName = 'master_menu_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(
            new ProductsExport(Product::query()->orderBy('name')->get()),
            $fileName
        );
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        try {
            $import = new ProductsImport(commit: false);
            Excel::import($import, $request->file('excel_file'));

            $summary = $import->previewSummary();

            if ($summary['total'] === 0) {
                return redirect()
                    ->route('adminapp.products.index')
                    ->with('error', 'Import master menu gagal. File Excel tidak berisi data menu.');
            }

            session([
                self::IMPORT_PREVIEW_SESSION_KEY => [
                    'file_name' => $request->file('excel_file')->getClientOriginalName(),
                    'rows' => $import->previewRows(),
                    'summary' => $summary,
                ],
            ]);

            $message = "Preview import siap. {$summary['created']} data baru dan {$summary['updated']} data update akan diproses setelah commit.";

            return redirect()
                ->route('adminapp.products.index')
                ->with('success', $message);
        } catch (\Throwable $exception) {
            return redirect()
                ->route('adminapp.products.index')
                ->with('error', 'Import master menu gagal. ' . $exception->getMessage());
        }
    }

    public function commitImportExcel()
    {
        $preview = session(self::IMPORT_PREVIEW_SESSION_KEY);

        if (! $preview || empty($preview['rows'])) {
            return redirect()
                ->route('adminapp.products.index')
                ->with('error', 'Tidak ada preview import yang bisa di-commit. Upload file Excel dulu.');
        }

        try {
            $import = new ProductsImport();
            $import->commitRows($preview['rows']);
            session()->forget(self::IMPORT_PREVIEW_SESSION_KEY);

            $summary = $import->summary();
            $message = "Import master menu berhasil di-commit. {$summary['created']} data baru ditambahkan dan {$summary['updated']} data diperbarui.";

            return redirect()
                ->route('adminapp.products.index')
                ->with('success', $message);
        } catch (\Throwable $exception) {
            return redirect()
                ->route('adminapp.products.index')
                ->with('error', 'Commit import master menu gagal. ' . $exception->getMessage());
        }
    }

    public function cancelImportExcel()
    {
        session()->forget(self::IMPORT_PREVIEW_SESSION_KEY);

        return redirect()
            ->route('adminapp.products.index')
            ->with('success', 'Preview import master menu dibatalkan.');
    }

    protected function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:50'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'raw_material_cost' => ['nullable', 'numeric', 'min:0'],
            'overhead_cost' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'is_3s' => ['nullable', 'boolean'],
        ]);
    }
}
