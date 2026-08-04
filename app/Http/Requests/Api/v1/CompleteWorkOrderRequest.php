<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class CompleteWorkOrderRequest extends FormRequest
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

        if (! $this->has('consumed_sku') && $this->has('consumedSku')) {
            $merge['consumed_sku'] = $this->input('consumedSku');
        }

        if (! $this->has('consumed_qty') && $this->has('consumedQty')) {
            $merge['consumed_qty'] = $this->input('consumedQty');
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
            'consumed_sku' => ['required', 'string', 'max:255'],
            'consumed_qty' => ['required', 'numeric', 'min:0.0001'],
        ];
    }
}
