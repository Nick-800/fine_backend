<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateImportOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => 'sometimes|uuid|exists:suppliers,id',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|uuid|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_price' => 'required|numeric|min:0.0001',
        ];
    }
}
