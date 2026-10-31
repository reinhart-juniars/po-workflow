<?php

namespace App\Services;

use App\Models\ProductionOrder;
use App\Models\Requisition;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dokumen cetak produksi: SPK, Form Kebutuhan, dan Lembar Plating.
 *
 * Form kebutuhan dicetak dengan kolom tanda tangan karena sebagian alur
 * persetujuan di klien masih berjalan di atas kertas; jejak digitalnya tetap
 * ada di tabel requisitions.
 */
class ProductionDocumentService
{
    public function __construct(
        protected ProductionOrderService $orders,
        protected PlatingService $plating,
    ) {}

    public function productionOrderPdf(ProductionOrder $order): StreamedResponse
    {
        $order->loadMissing(['lines.recipe', 'tasks']);

        return $this->stream('pdf.production-order', [
            'order' => $order,
            'requirements' => $this->orders->requirements($order),
        ], $order->number.'.pdf');
    }

    /**
     * Form kebutuhan dicetak mendatar: dua belas kolom angka terpotong di A4
     * tegak.
     *
     * @param  list<int>|null  $lineIds  Baris yang dicetak; null = semua. Bahan yang
     *                                   stoknya masih cukup tidak perlu ikut di kertas.
     */
    public function requisitionPdf(Requisition $requisition, ?array $lineIds = null): StreamedResponse
    {
        $requisition->loadMissing(['lines', 'productionOrder', 'preparedBy', 'approvedBy', 'checkedBy', 'purchaseBill']);

        $lines = $lineIds === null
            ? $requisition->lines
            : $requisition->lines->whereIn('id', array_map('intval', $lineIds))->values();

        return $this->stream('pdf.requisition', [
            'requisition' => $requisition,
            'lines' => $lines,
        ], $requisition->number.'.pdf', 'landscape');
    }

    /**
     * Baris yang dicentang bawaan saat mencetak: yang masih perlu dibeli.
     * Bila belum ada yang perlu dibeli (mis. Beli belum dihitung), semuanya.
     *
     * @return list<int>
     */
    public function defaultPrintLineIds(Requisition $requisition): array
    {
        $toBuy = $requisition->lines->filter(fn ($line) => (float) ($line->purchase_qty ?? 0) > 0);

        return ($toBuy->isEmpty() ? $requisition->lines : $toBuy)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @param  string  $tampilan  global (satu tabel) | kartu (komponen per menu) */
    public function platingPdf(ProductionOrder $order, string $tampilan = 'global'): StreamedResponse
    {
        return $this->stream('pdf.plating', [
            'order' => $order,
            'sheet' => $this->plating->sheet($order),
            'tampilan' => $tampilan === 'kartu' ? 'kartu' : 'global',
        ], 'plating-'.$order->number.'.pdf');
    }

    /**
     * Unduhan sebagai stream: aksi Filament/Livewire hanya bisa mengantarkan
     * berkas lewat StreamedResponse, bukan Response biner biasa.
     *
     * @param  array<string, mixed>  $data
     */
    protected function stream(string $view, array $data, string $filename, string $orientation = 'portrait'): StreamedResponse
    {
        $pdf = Pdf::loadView($view, $data)->setPaper('a4', $orientation);

        return response()->streamDownload(fn () => print ($pdf->output()), $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
