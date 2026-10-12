<?php

namespace App\Http\Controllers;

use App\Models\PurchaseBill;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto/scan nota Tagihan Pembelian. Disimpan di disk privat (bukan public)
 * karena memuat harga beli & nama supplier; dibuka gudang dan accounting
 * lewat policy yang sama dengan daftar tagihan.
 */
class PurchaseBillReceiptController extends Controller
{
    public function show(PurchaseBill $purchaseBill): StreamedResponse
    {
        abort_unless(auth()->user()?->can('viewAny', PurchaseBill::class), 403);
        abort_if(blank($purchaseBill->receipt_path) || ! Storage::disk('local')->exists($purchaseBill->receipt_path), 404);

        return Storage::disk('local')->response(
            $purchaseBill->receipt_path,
            'nota-'.$purchaseBill->number.'.'.pathinfo($purchaseBill->receipt_path, PATHINFO_EXTENSION),
            ['X-Content-Type-Options' => 'nosniff'],
            'inline',
        );
    }
}
