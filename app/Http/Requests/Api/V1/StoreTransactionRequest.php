<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'category_id' => [$required, 'integer', 'exists:categories,id'],
            'type' => [$required, Rule::in(['income', 'expense'])],
            'amount' => [$required, 'numeric', 'min:0.01'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'occurred_on' => [$required, 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'attachment' => ['nullable', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp,pdf'],
        ];
    }
}
