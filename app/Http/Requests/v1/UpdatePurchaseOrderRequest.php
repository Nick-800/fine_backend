<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => 'sometimes|uuid|exists:suppliers,id',
            'destination_warehouse_id' => 'sometimes|nullable|uuid|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|uuid|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_price' => 'required|numeric|min:0.0001',
        ];
    }

    /**
     * The destination warehouse must belong to the same operating unit as
     * the order being edited.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $warehouseId = $this->input('destination_warehouse_id');
            $orderId = $this->route('id') ?? $this->route('purchaseOrder');
            if (! $warehouseId || ! $orderId) {
                return;
            }

            $order = PurchaseOrder::query()->whereKey($orderId)->first();
            if ($order === null) {
                return;
            }

            $warehouse = Warehouse::query()->whereKey($warehouseId)->first();
            if ($warehouse === null) {
                return;
            }

            if ((string) $warehouse->operating_unit_id !== (string) $order->operating_unit_id) {
                $v->errors()->add(
                    'destination_warehouse_id',
                    'المخزن المختار لا ينتمي إلى نفس الوحدة التشغيلية لأمر الشراء.',
                );
            }
        });
    }
}
