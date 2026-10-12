<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/** Kembalikan Tagihan Pembelian ke gudang dengan alasan. */
class ReturnPurchaseBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase.pay') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'return_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'return_reason.required' => 'Tulis alasan supaya gudang tahu apa yang harus diperbaiki.',
            'return_reason.min' => 'Alasan terlalu pendek.',
        ];
    }
}
