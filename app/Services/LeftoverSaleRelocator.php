<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\SalesActual;
use App\Models\SalesActualItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Memindahkan Penjualan Barang Sisa yang salah masuk ke Sales Actual lama.
 *
 * Kasus 12 Nov 2026: Barang Sisa 10-11 Nov dijual ke customer yang masih punya
 * draft V2 (April-Mei), jadi barisnya ikut bertanggal April-Mei, lalu draft V2
 * itu tersubmit. Akibatnya penjualannya tidak muncul di tanggal yang benar,
 * menu V2 di draft itu ikut terhitung penjualan November (laporan berbasis
 * submitted_at), dan tunainya tidak ikut closing harian.
 *
 * Yang dilakukan, dalam satu transaksi:
 * 1. Tiap baris dipindah ke draft customer yang sama bertanggal $date (dibuat
 *    bila belum ada). Baris yang ikut PO dari DO Sales Actual lama dibayar
 *    Tunai ke $cashAccountId -- PO lama tidak boleh menagih penjualan baru.
 * 2. Sales Actual asal yang bertanggal sebelum awal V3 tetapi disubmit sesudah
 *    awal V3 dikembalikan ke draft, persis keadaannya di V2.
 * Semua langkah tercatat di audit log supaya accounting bisa melacak.
 */
class LeftoverSaleRelocator
{
    /**
     * Rencana pemindahan tanpa menulis apa pun. Melempar RuntimeException bila
     * ada baris yang tidak boleh dipindah.
     *
     * @param  array<int, int>  $itemIds
     * @return array{moves: list<array<string, mixed>>, reopen: list<array<string, mixed>>}
     */
    public function plan(array $itemIds, Carbon $date, ?int $cashAccountId): array
    {
        $items = SalesActualItem::query()
            ->with(['salesActual.customer', 'sourceSalesActualItem.salesActual'])
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        if ($cashAccountId !== null && ! CashAccount::query()->whereKey($cashAccountId)->where('is_active', true)->exists()) {
            throw new RuntimeException("Akun kas #{$cashAccountId} tidak ditemukan atau tidak aktif.");
        }

        $moves = [];

        foreach ($itemIds as $id) {
            /** @var SalesActualItem|null $item */
            $item = $items->get($id);
            $this->assertMovable($id, $item, $date);

            $followsPo = $item->cash_account_id === null && $item->purchase_order_id !== null;

            if ($followsPo && $cashAccountId === null) {
                throw new RuntimeException(sprintf(
                    'Baris #%d (%s) ikut PO #%d dari Sales Actual lama. Isi --cash-account dengan akun kas Tunai penjualan ini.',
                    $item->id, $item->item_name, $item->purchase_order_id
                ));
            }

            $moves[] = [
                'item' => $item,
                'from' => $item->salesActual,
                'customer' => $item->salesActual->customer,
                'cash_account_id' => $followsPo ? $cashAccountId : $item->cash_account_id,
                'purchase_order_id' => $followsPo ? null : $item->purchase_order_id,
            ];
        }

        return ['moves' => $moves, 'reopen' => $this->reopenCandidates(collect($moves)->pluck('from')->unique('id'))];
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return array{moves: list<array<string, mixed>>, reopen: list<array<string, mixed>>, targets: array<int, SalesActual>}
     */
    public function relocate(array $itemIds, Carbon $date, ?int $cashAccountId): array
    {
        return DB::transaction(function () use ($itemIds, $date, $cashAccountId) {
            // Kunci baris dulu supaya tidak ada yang mengubahnya di tengah pemindahan.
            SalesActualItem::query()->whereIn('id', $itemIds)->lockForUpdate()->get();

            $plan = $this->plan($itemIds, $date, $cashAccountId);
            $targets = [];

            foreach ($plan['moves'] as $move) {
                /** @var SalesActualItem $item */
                $item = $move['item'];
                /** @var SalesActual $from */
                $from = $move['from'];
                $target = $targets[$from->customer_id] ??= $this->targetDraft($from, $date);
                $before = $item->only(['sales_actual_id', 'purchase_order_id', 'cash_account_id']);

                $item->forceFill([
                    'sales_actual_id' => $target->id,
                    'purchase_order_id' => $move['purchase_order_id'],
                    'cash_account_id' => $move['cash_account_id'],
                ])->save();

                $this->audit($target->id, 'sales_actual_leftover_moved', sprintf(
                    'Barang Sisa %s (%s) dipindah dari Sales Actual #%d tgl %s ke #%d tgl %s (%s): sebelumnya salah masuk draft lama.',
                    $item->item_name,
                    number_format((float) $item->qty_actual, 2, ',', '.'),
                    $from->id,
                    $from->sales_date?->format('d/m/Y'),
                    $target->id,
                    $target->sales_date?->format('d/m/Y'),
                    $move['cash_account_id'] ? 'Tunai ke akun kas #'.$move['cash_account_id'] : 'ikut PO #'.$move['purchase_order_id'],
                ), $before, $item->only(['sales_actual_id', 'purchase_order_id', 'cash_account_id']) + ['item_id' => $item->id]);
            }

            foreach ($plan['reopen'] as $row) {
                /** @var SalesActual $salesActual */
                $salesActual = $row['sales_actual'];
                $before = $salesActual->only(['status', 'submitted_at', 'submitted_by']);

                $salesActual->forceFill(['status' => 'draft', 'submitted_at' => null, 'submitted_by' => null])->save();

                $this->audit($salesActual->id, 'sales_actual_reopened', sprintf(
                    'Sales Actual #%d tgl %s dikembalikan ke draft: draft V2 ini tersubmit %s karena Penjualan Barang Sisa salah masuk ke sini.',
                    $salesActual->id,
                    $salesActual->sales_date?->format('d/m/Y'),
                    Carbon::parse($before['submitted_at'])->format('d/m/Y H:i'),
                ), $before, ['status' => 'draft']);
            }

            return $plan + ['targets' => $targets];
        });
    }

    protected function assertMovable(int $id, ?SalesActualItem $item, Carbon $date): void
    {
        if (! $item) {
            throw new RuntimeException("Baris Sales Actual #{$id} tidak ditemukan.");
        }

        $entryDate = $item->sourceSalesActualItem?->salesActual?->sales_date;

        if ($item->source_sales_actual_item_id === null || $entryDate === null) {
            throw new RuntimeException("Baris #{$id} ({$item->item_name}) bukan Penjualan Barang Sisa.");
        }

        $from = $item->salesActual;

        // Hanya yang salah tempat: Sales Actual-nya bertanggal sebelum sisanya ada.
        if ($from->sales_date->gte($entryDate)) {
            throw new RuntimeException(sprintf(
                'Baris #%d (%s) sudah di Sales Actual #%d tgl %s, tidak sebelum tanggal sisanya (%s).',
                $id, $item->item_name, $from->id, $from->sales_date->format('d/m/Y'), $entryDate->format('d/m/Y')
            ));
        }

        if ($from->is_internal) {
            throw new RuntimeException("Baris #{$id} ada di Sales Actual internal #{$from->id}.");
        }

        if ($from->sales_daily_closing_id !== null) {
            throw new RuntimeException("Sales Actual #{$from->id} sudah masuk closing harian; batalkan closing-nya dulu.");
        }

        if ($date->lt($entryDate)) {
            throw new RuntimeException(sprintf('Tanggal tujuan %s sebelum tanggal Barang Sisa %s.', $date->format('d/m/Y'), $entryDate->format('d/m/Y')));
        }
    }

    /**
     * Sales Actual asal yang perlu kembali ke draft: bertanggal sebelum awal V3
     * dan disubmit sesudahnya. Yang disubmit sebelum awal V3 sudah sah di V2.
     *
     * @param  Collection<int, SalesActual>  $sources
     * @return list<array{sales_actual: SalesActual}>
     */
    protected function reopenCandidates(Collection $sources): array
    {
        $start = Carbon::parse(SalesActual::V3_START)->startOfDay();

        return $sources
            ->filter(fn (SalesActual $sa) => $sa->isSubmitted()
                && $sa->sales_date->lt($start)
                && $sa->submitted_at?->gte($start))
            ->map(fn (SalesActual $sa) => ['sales_actual' => $sa])
            ->values()
            ->all();
    }

    protected function targetDraft(SalesActual $from, Carbon $date): SalesActual
    {
        $draft = SalesActual::query()
            ->external()
            ->where('customer_id', $from->customer_id)
            ->where('status', 'draft')
            ->whereDate('sales_date', $date->toDateString())
            ->orderBy('id')
            ->first();

        if ($draft) {
            return $draft;
        }

        $draft = SalesActual::query()->create([
            'customer_id' => $from->customer_id,
            'delivery_order_id' => null,
            'sales_date' => $date->toDateString(),
            'status' => 'draft',
            'is_internal' => false,
            'notes' => null,
        ]);

        $this->audit($draft->id, 'sales_actual_draft_created', sprintf(
            'Draft Sales Actual #%d (tanpa DO) tgl %s dibuat untuk menampung Penjualan Barang Sisa yang dipindah dari #%d.',
            $draft->id, $date->format('d/m/Y'), $from->id
        ), null, ['customer_id' => $from->customer_id, 'sales_date' => $date->toDateString()]);

        return $draft;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    protected function audit(int $salesActualId, string $action, string $message, ?array $before, array $after): void
    {
        AuditLog::query()->create([
            'user_id' => null,
            'entity' => 'sales_actual',
            'entity_id' => $salesActualId,
            'purchase_order_id' => null,
            'action' => $action,
            'message' => $message,
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => null,
        ]);
    }
}
