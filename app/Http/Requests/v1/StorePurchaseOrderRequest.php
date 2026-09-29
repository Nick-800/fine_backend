<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Models\Warehouse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class StorePurchaseOrderRequest extends FormRequest
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
            'kind' => 'sometimes|string|in:foreign,local',
            'negotiated_price' => 'required_without:items|numeric|min:0.0001',
            'quantity' => 'required_without:items|numeric|min:0.0001',
            'booked_fx_rate' => 'sometimes|nullable|numeric|min:0.000001',
            'destination_warehouse_id' => 'sometimes|nullable|uuid|exists:warehouses,id',
            'items' => 'sometimes|array|min:1',
            'items.*.inventory_item_id' => 'required_with:items|uuid|exists:inventory_items,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.unit_price' => 'required_with:items|numeric|min:0.0001',
        ];
    }

    /**
     * The destination warehouse must belong to the same operating unit as
     * the order. Cross-unit posting would silently misroute the goods.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $warehouseId = $this->input('destination_warehouse_id');
            $unitId = $this->input('operating_unit_id');
            if (! $warehouseId || ! $unitId) {
                return;
            }

            $warehouse = Warehouse::query()->whereKey($warehouseId)->first();
            if ($warehouse === null) {
                return;
            }

            if ((string) $warehouse->operating_unit_id !== (string) $unitId) {
                $v->errors()->add(
                    'destination_warehouse_id',
                    'المخزن المختار لا ينتمي إلى نفس الوحدة التشغيلية لأمر الشراء.',
                );
            }
        });
    }
}
