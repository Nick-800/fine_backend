<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

final class StoreImportOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operating_unit_id' => 'required|uuid|exists:operating_units,id',
            'supplier_id' => 'required|uuid|exists:suppliers,id',
            'currency' => 'sometimes|string|size:3',
            'negotiated_price' => 'required_without:items|numeric|min:0.0001',
            'quantity' => 'required_without:items|numeric|min:0.0001',
            'booked_fx_rate' => 'sometimes|nullable|numeric|min:0.000001',
            'items' => 'sometimes|array|min:1',
            'items.*.inventory_item_id' => 'required_with:items|uuid|exists:inventory_items,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.unit_price' => 'required_with:items|numeric|min:0.0001',
        ];
    }
}
