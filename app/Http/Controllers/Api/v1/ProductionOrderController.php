<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\ProductionOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\Employee;
use App\Models\ProductionOrder;
use App\Services\ProductionOrderService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductionOrderController extends Controller
{
    public function __construct(
        public ProductionOrderService $productionOrderService,
        public CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ProductionOrder::with(['product', 'client'])->latest();

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if ($request->boolean('stock_only')) {
            $query->whereNull('client_id');
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:100', 'unique:production_orders,order_number'],
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            // Optional: defaults to the product's active BOM. A custom order
            // passes its adapted clone here instead.
            'bom_id' => ['nullable', 'uuid', 'exists:boms,id'],
            'client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before creating a production order.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $bomId = $validated['bom_id']
            ?? Bom::where('product_id', $validated['product_id'])->where('is_active', true)->value('id');

        // FUR-01: no active BOM means there is nothing defined to build.
        if ($bomId === null) {
            return response()->json([
                'message' => 'This product has no active BOM; activate one or pass bom_id.',
                'code' => 'NO_ACTIVE_BOM',
            ], 422);
        }

        $order = ProductionOrder::create([
            ...$validated,
            'bom_id' => $bomId,
            'quantity' => $validated['quantity'] ?? 1,
            'operating_unit_id' => $unitId,
        ]);

        return response()->json($order->load(['product', 'bom']), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            ProductionOrder::with([
                'product.inventoryItem',
                'bom.componentLines.inventoryItem',
                'bom.laborRequirements',
                'client',
                'laborLogs.employee.entity',
                'finishedStockLot',
            ])->findOrFail($id)
        );
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $order = ProductionOrder::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ProductionOrderStatus::class)],
        ]);

        $updated = $this->productionOrderService->transition(
            $order,
            ProductionOrderStatus::from($validated['status'])
        );

        return response()->json($updated);
    }

    public function laborLogs(string $id): JsonResponse
    {
        $order = ProductionOrder::findOrFail($id);

        return response()->json($order->laborLogs()->with('employee.entity')->latest('logged_at')->get());
    }

    public function storeLaborLog(Request $request, string $id): JsonResponse
    {
        $order = ProductionOrder::findOrFail($id);

        $validated = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'role' => ['required', 'string', 'max:50'],
            'hours_logged' => ['required', 'numeric', 'gt:0'],
            // Optional — defaults from the BOM's matching labor requirement.
            'hourly_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $log = $this->productionOrderService->logLabor(
            $order,
            Employee::findOrFail($validated['employee_id']),
            $validated['role'],
            (float) $validated['hours_logged'],
            isset($validated['hourly_rate']) ? (float) $validated['hourly_rate'] : null,
        );

        return response()->json($log->load('employee.entity'), 201);
    }
}
