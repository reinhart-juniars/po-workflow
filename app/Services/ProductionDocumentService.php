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

    public function requisitionPdf(Requisition $requisition): StreamedResponse
    {
        $requisition->loadMissing(['lines', 'productionOrder', 'preparedBy', 'approvedBy', 'checkedBy']);

        return $this->stream('pdf.requisition', ['requisition' => $requisition], $requisition->number.'.pdf');
    }

    public function platingPdf(ProductionOrder $order): StreamedResponse
    {
        return $this->stream('pdf.plating', [
            'order' => $order,
            'sheet' => $this->plating->sheet($order),
        ], 'plating-'.$order->number.'.pdf');
    }

    /**
     * Unduhan sebagai stream: aksi Filament/Livewire hanya bisa mengantarkan
     * berkas lewat StreamedResponse, bukan Response biner biasa.
     *
     * @param  array<string, mixed>  $data
     */
    protected function stream(string $view, array $data, string $filename): StreamedResponse
    {
        $pdf = Pdf::loadView($view, $data)->setPaper('a4', 'portrait');

        return response()->streamDownload(fn () => print ($pdf->output()), $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
