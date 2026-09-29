<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\SaleBundleComponent;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockLot;
use App\Services\BundleFulfillmentService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A sold bundle after checkout: define its pieces, set them aside from stock,
 * hand them over, and print the price-less delivery note. Components carry no
 * unit of their own — every lookup goes through the sale, whose unit scope
 * keeps one showroom out of another's bundles.
 */
final class SaleBundleController extends Controller
{
    public function __construct(private readonly BundleFulfillmentService $fulfillment) {}

    public function define(Request $request, string $id, string $lineId): JsonResponse
    {
        $validated = $request->validate([
            'components' => ['required', 'array', 'min:1'],
            'components.*.inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'components.*.quantity' => ['required', 'numeric', 'gt:0'],
            'components.*.length_m' => ['nullable', 'numeric', 'gt:0'],
            'components.*.width_m' => ['nullable', 'numeric', 'gt:0'],
            'components.*.height_m' => ['nullable', 'numeric', 'gt:0'],
            'components.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $sale = SalesOrder::findOrFail($id);
        $line = SalesOrderLine::where('sales_order_id', $sale->id)->findOrFail($lineId);

        return response()->json($this->fulfillment->define($line, $validated['components']));
    }

    public function matchingStock(string $componentId): JsonResponse
    {
        $component = $this->component($componentId);

        return response()->json([
            'component_id' => $component->id,
            'needed' => (float) $component->quantity,
            'lots' => $this->fulfillment->matchingStock($component)->map(fn (StockLot $lot) => [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'quantity' => (float) $lot->quantity,
                'length_m' => $lot->length_m !== null ? (float) $lot->length_m : null,
                'width_m' => $lot->width_m !== null ? (float) $lot->width_m : null,
                'height_m' => $lot->height_m !== null ? (float) $lot->height_m : null,
                'grade' => $lot->grade,
                'warehouse' => $lot->warehouse?->name,
                'operating_unit' => $lot->warehouse?->operatingUnit?->name,
                'operating_unit_id' => $lot->warehouse?->operating_unit_id,
            ])->values(),
        ]);
    }

    public function reserve(Request $request, string $componentId): JsonResponse
    {
        $validated = $request->validate([
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.stock_lot_id' => ['required', 'uuid'],
            'allocations.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        return response()->json($this->fulfillment->reserve($this->component($componentId), $validated['allocations']));
    }

    public function release(string $componentId): JsonResponse
    {
        return response()->json($this->fulfillment->release($this->component($componentId)));
    }

    /**
     * Hand over ready pieces — all of them, or the ones named.
     */
    public function deliver(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'component_ids' => ['nullable', 'array'],
            'component_ids.*' => ['uuid'],
        ]);

        return response()->json($this->fulfillment->deliver(SalesOrder::findOrFail($id), $validated['component_ids'] ?? null));
    }

    /**
     * Send pending pieces to the cutter: into a named open cutter order, or a
     * new one.
     */
    public function sendToCutter(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'component_ids' => ['required', 'array', 'min:1'],
            'component_ids.*' => ['uuid'],
            'cutter_work_order_id' => ['nullable', 'uuid'],
        ]);

        $order = $this->fulfillment->sendToCutter(
            SalesOrder::findOrFail($id),
            $validated['component_ids'],
            $validated['cutter_work_order_id'] ?? null,
        );

        return response()->json($order, 201);
    }

    /**
     * Open cutter orders the counter's pieces may join.
     */
    public function openCutterOrders(Request $request): JsonResponse
    {
        $unit = app(CurrentUnitContext::class)->unit();

        return response()->json([
            'data' => $unit !== null ? $this->fulfillment->openCutterOrdersFor($unit) : [],
        ]);
    }

    public function deliveryNote(string $id): JsonResponse
    {
        return response()->json($this->fulfillment->deliveryNote(SalesOrder::findOrFail($id)));
    }

    private function component(string $componentId): SaleBundleComponent
    {
        return SaleBundleComponent::with('line')
            ->whereHas('line.salesOrder')
            ->findOrFail($componentId);
    }
}
