<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        // Support 'productSku' (camelCase) and 'sku' aliases for 'product_sku'
        if (! $this->has('product_sku')) {
            if ($this->has('productSku')) {
                $merge['product_sku'] = $this->input('productSku');
            } elseif ($this->has('sku')) {
                $merge['product_sku'] = $this->input('sku');
            }
        }

        // Default status to 'pending' if not provided
        if (! $this->has('status') || blank($this->input('status'))) {
            $merge['status'] = 'pending';
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operating_unit_id' => ['nullable', 'uuid', 'exists:operating_units,id'],
            'product_sku' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'status' => ['required', 'string', 'max:50'],
        ];
    }
}
