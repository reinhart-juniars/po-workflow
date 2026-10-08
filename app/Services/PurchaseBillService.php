<?php

namespace App\Services;

use App\Http\Controllers\Concerns\ResolvesCentralExpenseLocation;
use App\Models\CashAccount;
use App\Models\CashOut;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryPurchase;
use App\Models\Payable;
use App\Models\PeriodClosing;
use App\Models\PurchaseBill;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Support\Notify;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tagihan Pembelian: gudang belanja, lalu menagih ke accounting.
 *
 * Pembukuannya dijaga supaya Neraca seimbang di setiap tahap:
 *
 *   Barang diterima   Persediaan +X   Hutang (tagihan) +X     <- saat tagihan lahir
 *   Accounting bayar  Hutang −X       Kas −X                  <- pay()
 *
 * Barang yang sudah masuk gudang tapi belum dibayar adalah hutang, siapa pun
 * yang menalangi (supplier memberi tempo, atau staf memakai uang sendiri).
 * Karena itu hutangnya lahir bersama barangnya -- bukan saat accounting
 * menyetujui -- dan pembayarannya memakai kategori "Pembayaran Hutang" yang
 * sama dengan Pengeluaran › Pembayaran Kredit, jadi tidak dihitung dua kali
 * sebagai beban di Laba Rugi.
 *
 * Satu tagihan = satu hutang (bukan satu per baris bahan), dan hutang itu
 * hanya bisa dilunasi dari halaman Tagihan (Payable::settleableByCredit).
 */
class PurchaseBillService
{
    use ResolvesCentralExpenseLocation;

    /** Kategori sistem pelunasan hutang; sama dengan AccountingAppController. */
    public const SETTLEMENT_CATEGORY = 'Pembayaran Hutang';

    public function __construct(
        protected InventoryPurchaseFlowService $purchases,
        protected InventoryLedgerService $ledger,
    ) {}

    /** Tagihan draft untuk satu Form Kebutuhan (dipanggil saat Periksa). */
    public function openForRequisition(Requisition $requisition, string $date, ?int $userId): PurchaseBill
    {
        return PurchaseBill::query()->create([
            'status' => PurchaseBill::STATUS_DRAFT,
            'bill_date' => $date,
            'requisition_id' => $requisition->id,
            'supplier_id' => $requisition->supplier_id,
            'supplier_name' => $requisition->supplier_name,
            'created_by' => $userId,
        ]);
    }

    /**
     * Belanja lepas (bukan dari SPK): barang diterima saat tagihan dibuat.
     * Tiap baris jadi pembelian bahan baku + mutasi Kartu Stok.
     *
     * @param  array{bill_date: string, supplier_id?: ?int, supplier_name?: ?string, notes?: ?string, lines: list<array{inventory_item_id: int, qty: float|string, unit_cost: float|string}>}  $data
     */
    public function createStandalone(array $data, ?int $userId): PurchaseBill
    {
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($line) => (float) ($line['qty'] ?? 0) > 0));

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Isi minimal satu bahan dengan jumlah lebih dari nol.']);
        }

        $this->assertOpenPeriod($data['bill_date'], 'bill_date');

        return DB::transaction(function () use ($data, $lines, $userId) {
            $supplier = filled($data['supplier_id'] ?? null) ? Supplier::query()->findOrFail($data['supplier_id']) : null;

            $bill = PurchaseBill::query()->create([
                'status' => PurchaseBill::STATUS_DRAFT,
                'bill_date' => $data['bill_date'],
                'supplier_id' => $supplier?->id,
                'supplier_name' => $supplier?->name ?? ($data['supplier_name'] ?? null),
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $item = InventoryItem::query()->ingredients()->findOrFail($line['inventory_item_id']);

                $purchase = $this->purchases->create([
                    'inventory_item_id' => $item->id,
                    'purchase_bill_id' => $bill->id,
                    'transaction_date' => $data['bill_date'],
                    'qty' => (float) $line['qty'],
                    'unit_cost' => (float) $line['unit_cost'],
                    'payment_type' => InventoryPurchase::PAYMENT_BILL,
                    'supplier_id' => $bill->supplier_id,
                    'supplier_name' => $bill->supplier_name,
                    'condition' => InventoryPurchase::CONDITION_GOOD,
                    'notes' => 'Belanja lepas '.$bill->number,
                ], $userId);

                $this->ledger->post($item->id, InventoryMovement::TYPE_PURCHASE, (float) $line['qty'], (string) $item->unit, $data['bill_date'], (float) $line['unit_cost'], [
                    'inventory_purchase_id' => $purchase->id,
                    'notes' => 'Belanja lepas '.$bill->number,
                    'created_by' => $userId,
                ]);
            }

            return $this->syncPayable($bill);
        });
    }

    /**
     * Hitung ulang total dari pembeliannya dan samakan hutangnya. Dipanggil
     * setiap kali isi tagihan berubah selama belum dibayar.
     */
    public function syncPayable(PurchaseBill $bill): PurchaseBill
    {
        $total = round((float) $bill->purchases()->sum('total_value'), 2);

        if ($bill->status === PurchaseBill::STATUS_PAID) {
            throw ValidationException::withMessages(['bill' => 'Tagihan '.$bill->number.' sudah dibayar; isinya tidak bisa berubah.']);
        }

        $payable = Payable::query()->updateOrCreate(
            ['id' => $bill->payable_id],
            [
                'transaction_date' => $bill->bill_date,
                // Belum diputuskan accounting: jatuh tempo bawaan = termin
                // bayar supplier, supaya Monitoring Hutang bisa mengingatkan.
                'due_date' => $bill->due_date ?? $bill->supplier?->dueDateFor($bill->bill_date?->toDateString()),
                'supplier_id' => $bill->supplier_id,
                'supplier_name' => $bill->displaySupplier(),
                'description' => 'Tagihan Pembelian '.$bill->number,
                'amount' => $total,
                'status' => 'unpaid',
                'paid_at' => null,
                'created_by' => $bill->created_by,
            ]
        );

        $bill->forceFill(['total' => $total, 'payable_id' => $payable->id])->save();

        return $bill->refresh();
    }

    /** Gudang mengajukan tagihan ke accounting. */
    public function submit(PurchaseBill $bill, ?int $userId): PurchaseBill
    {
        if (! $bill->isEditableByInventory()) {
            throw ValidationException::withMessages(['bill' => 'Tagihan '.$bill->number.' sudah di accounting.']);
        }

        if ((float) $bill->total <= 0) {
            throw ValidationException::withMessages(['bill' => 'Tagihan '.$bill->number.' belum berisi pembelian.']);
        }

        $bill->update([
            'status' => PurchaseBill::STATUS_SUBMITTED,
            'submitted_by' => $userId,
            'submitted_at' => now(),
            'return_reason' => null,
        ]);

        Notify::permission(
            'purchase.pay',
            'Tagihan pembelian '.$bill->number.' menunggu dibayar',
            $bill->displaySupplier().' · Rp '.number_format((float) $bill->total, 0, ',', '.').($bill->receipt_path ? '' : ' · tanpa foto nota'),
            route('accountingapp.purchase-bills.show', $bill),
            'warning',
            $userId,
        );

        return $bill;
    }

    /**
     * Accounting membayar: kas keluar sebagai pelunasan hutang tagihan.
     *
     * @param  array{cash_account_id: int|string, paid_on: string}  $data
     */
    public function pay(PurchaseBill $bill, array $data, ?int $userId): PurchaseBill
    {
        if (! $bill->isPayable()) {
            throw ValidationException::withMessages(['bill' => 'Tagihan '.$bill->number.' tidak sedang menunggu pembayaran.']);
        }

        $account = CashAccount::query()->where('is_active', true)->find($data['cash_account_id']);

        if (! $account) {
            throw ValidationException::withMessages(['cash_account_id' => 'Akun kas tidak ditemukan atau nonaktif.']);
        }

        $this->assertOpenPeriod($data['paid_on'], 'paid_on');

        return DB::transaction(function () use ($bill, $account, $data, $userId) {
            $bill = PurchaseBill::query()->lockForUpdate()->findOrFail($bill->id);
            $payable = Payable::query()->lockForUpdate()->findOrFail($bill->payable_id);

            if ($payable->status === 'paid') {
                throw ValidationException::withMessages(['bill' => 'Hutang tagihan '.$bill->number.' sudah lunas.']);
            }

            $cashOut = CashOut::query()->create([
                'expense_category_id' => $this->settlementCategory()->id,
                'expense_location_id' => $this->centralExpenseLocationId(),
                'cash_account_id' => $account->id,
                'payable_id' => $payable->id,
                'amount' => (float) $payable->amount,
                'expense_date' => $data['paid_on'],
                'description' => 'Pembayaran Tagihan Pembelian '.$bill->number.' ('.$bill->displaySupplier().')',
                'is_adjustment' => false,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $payable->update(['status' => 'paid', 'paid_at' => now(), 'updated_by' => $userId]);

            $bill->update([
                'status' => PurchaseBill::STATUS_PAID,
                'cash_out_id' => $cashOut->id,
                'cash_account_id' => $account->id,
                'paid_on' => $data['paid_on'],
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);

            $this->notifySubmitter($bill, 'Tagihan '.$bill->number.' sudah dibayar', 'Dibayar dari '.$account->name.' pada '.Carbon::parse($data['paid_on'])->translatedFormat('d M Y').'.', 'success');

            return $bill;
        });
    }

    /** Supplier memberi tempo: tagihan tetap terbuka sebagai hutang supplier. */
    public function markCredit(PurchaseBill $bill, string $dueDate, ?int $userId): PurchaseBill
    {
        if ($bill->status !== PurchaseBill::STATUS_SUBMITTED) {
            throw ValidationException::withMessages(['bill' => 'Hanya tagihan yang baru diajukan yang bisa dijadikan hutang supplier.']);
        }

        DB::transaction(function () use ($bill, $dueDate, $userId) {
            $bill->update(['status' => PurchaseBill::STATUS_CREDIT, 'due_date' => $dueDate, 'decided_by' => $userId, 'decided_at' => now()]);
            $bill->payable?->update(['due_date' => $dueDate, 'updated_by' => $userId]);
        });

        return $bill;
    }

    /** Accounting mengembalikan ke gudang (nota kurang, salah supplier, dll.). */
    public function returnToInventory(PurchaseBill $bill, string $reason, ?int $userId): PurchaseBill
    {
        if ($bill->status !== PurchaseBill::STATUS_SUBMITTED) {
            throw ValidationException::withMessages(['bill' => 'Hanya tagihan yang baru diajukan yang bisa dikembalikan.']);
        }

        $bill->update(['status' => PurchaseBill::STATUS_RETURNED, 'return_reason' => $reason, 'decided_by' => $userId, 'decided_at' => now()]);

        $this->notifySubmitter($bill, 'Tagihan '.$bill->number.' dikembalikan accounting', $reason, 'danger');

        return $bill;
    }

    protected function notifySubmitter(PurchaseBill $bill, string $title, string $body, string $status): void
    {
        $recipient = $bill->submitter ?? $bill->creator;

        if ($recipient) {
            Notify::users(collect([$recipient]), $title, $body, route('filament.admin.resources.purchase-bills.edit', $bill), $status);
        }
    }

    protected function settlementCategory(): ExpenseCategory
    {
        return ExpenseCategory::query()->firstOrCreate(
            ['name' => self::SETTLEMENT_CATEGORY],
            [
                'description' => 'Kategori sistem untuk pelunasan hutang dagang.',
                'expense_mode' => ExpenseCategory::MODE_DIRECT_EXPENSE,
                'include_hpp' => false,
                'is_active' => true,
            ]
        );
    }

    /** Transaksi baru tidak boleh masuk ke periode yang sudah tutup buku. */
    protected function assertOpenPeriod(string $date, string $field): void
    {
        $parsed = Carbon::parse($date);

        if (PeriodClosing::query()->where('period_month', $parsed->month)->where('period_year', $parsed->year)->exists()) {
            throw ValidationException::withMessages([$field => 'Periode '.$parsed->translatedFormat('F Y').' sudah ditutup.']);
        }
    }
}
