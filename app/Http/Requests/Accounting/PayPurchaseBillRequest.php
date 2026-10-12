<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/** Bayar Tagihan Pembelian dari akun kas (Accounting). */
class PayPurchaseBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase.pay') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Akun kas aktif dicek ulang di PurchaseBillService::pay.
            'cash_account_id' => ['required', 'integer'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cash_account_id.required' => 'Pilih akun kas yang dipakai membayar.',
            'paid_on.required' => 'Tanggal bayar wajib diisi.',
            'paid_on.before_or_equal' => 'Tanggal bayar tidak boleh di masa depan.',
        ];
    }
}
