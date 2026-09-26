<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\SalesOrderLine;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BundleController extends Controller
{
    use ResolvesReportScope;

    public function __construct(
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Bundle::with('items.inventoryItem');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->query('search').'%');
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateBundle($request);

        $bundle = DB::transaction(function () use ($validated) {
            $bundle = Bundle::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $bundle->items()->create($item);
            }

            return $bundle;
        });

        return response()->json($bundle->fresh('items.inventoryItem'), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Bundle::with('items.inventoryItem')->findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $bundle = Bundle::findOrFail($id);
        $validated = $this->validateBundle($request, sometimes: true);

        DB::transaction(function () use ($bundle, $validated): void {
            $bundle->update([
                'name' => $validated['name'] ?? $bundle->name,
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $bundle->description,
            ]);

            // Sync bundle_items by full replacement — same pattern as
            // ImportOrderController::update for import_order_items.
            $bundle->items()->delete();
            foreach ($validated['items'] as $item) {
                $bundle->items()->create($item);
            }
        });

        return response()->json($bundle->fresh('items.inventoryItem'));
    }

    public function destroy(string $id): JsonResponse
    {
        $bundle = Bundle::findOrFail($id);
        $bundle->delete();

        return response()->json(['message' => 'Bundle deleted successfully.']);
    }

    /** @return array{name?: string, description?: ?string, items: array<int, array{inventory_item_id: string, suggested_quantity: ?float}>} */
    private function validateBundle(Request $request, bool $sometimes = false): array
    {
        $nameRule = $sometimes ? ['sometimes', 'string', 'max:255'] : ['required', 'string', 'max:255'];

        return $request->validate([
            'name' => $nameRule,
            'description' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'items.*.suggested_quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);
    }

    /**
     * Orders count, total quantity and total revenue per bundle, over an
     * optional date range and operating unit — mirrors
     * FinancialReportController::unitProfitability's group-by-dimension shape.
     */
    public function salesReport(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        $query = SalesOrderLine::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->join('bundles', 'bundles.id', '=', 'sales_order_lines.bundle_id')
            ->whereNotNull('sales_order_lines.bundle_id')
            ->selectRaw('sales_order_lines.bundle_id as bundle_id')
            ->selectRaw('bundles.name as bundle_name')
            ->selectRaw('COUNT(DISTINCT sales_order_lines.sales_order_id) as orders_count')
            ->selectRaw('SUM(sales_order_lines.quantity) as total_quantity')
            ->selectRaw('SUM(sales_order_lines.quantity * sales_order_lines.unit_price) as total_revenue')
            ->groupBy('sales_order_lines.bundle_id', 'bundles.name');

        $from = $request->query('from');
        $to = $request->query('to');

        if ($from) {
            $query->whereDate('sales_orders.created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('sales_orders.created_at', '<=', $to);
        }

        if ($unitId = $this->resolveReportUnitId($request, $this->unitContext)) {
            $query->where('sales_orders.operating_unit_id', $unitId);
        }

        $rows = $query->get()->map(fn ($row) => [
            'bundle_id' => $row->bundle_id,
            'bundle_name' => $row->bundle_name,
            'orders_count' => (int) $row->orders_count,
            'total_quantity' => round((float) $row->total_quantity, 4),
            'total_revenue' => round((float) $row->total_revenue, 4),
        ])->sortByDesc('total_revenue')->values();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total_revenue' => round((float) $rows->sum('total_revenue'), 4),
        ]);
    }
}
