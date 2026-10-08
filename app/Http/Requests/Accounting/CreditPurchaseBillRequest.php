<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/** Jadikan Tagihan Pembelian hutang supplier dengan jatuh tempo. */
class CreditPurchaseBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase.pay') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'due_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'due_date.required' => 'Jatuh tempo wajib diisi.',
            'due_date.after_or_equal' => 'Jatuh tempo tidak boleh sebelum hari ini.',
        ];
    }
}
