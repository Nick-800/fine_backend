<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\AllocationMethod;
use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\OverheadAllocationRule;
use App\Models\OverheadExpense;
use App\Models\Scopes\OperatingUnitOrSharedScope;
use App\Services\OverheadService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class OverheadExpenseController extends Controller
{
    use ResolvesReportScope;

    public function __construct(
        private readonly OverheadService $overheadService,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = OverheadExpense::with(['operatingUnit', 'allocations.operatingUnit'])->latest('expense_date');

        if ($request->boolean('company_wide') && $this->hasCompanyWideRole($request)) {
            $query->withoutGlobalScope(OperatingUnitOrSharedScope::class);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'required|string|in:water,electricity,rent,maintenance,other',
            'description' => 'nullable|string|max:500',
            'amount' => 'required|numeric|min:0.0001',
            'expense_date' => 'required|date|before_or_equal:today',
            'payment_source' => 'required|string|in:cash,payable',
            // Explicit true = company-level expense awaiting allocation.
            'is_company_wide' => 'sometimes|boolean',
            'operating_unit_id' => 'sometimes|nullable|uuid|exists:operating_units,id',
        ]);

        $companyId = Company::query()->value('id');

        if ($companyId === null) {
            return response()->json(['message' => 'No company exists.'], 422);
        }

        $unitId = $request->boolean('is_company_wide')
            ? null
            : ($validated['operating_unit_id'] ?? $this->unitContext->getUnitId());

        try {
            $expense = $this->overheadService->recordExpense([
                'company_id' => $companyId,
                'operating_unit_id' => $unitId,
                'category' => $validated['category'],
                'description' => $validated['description'] ?? null,
                'amount' => (float) $validated['amount'],
                'expense_date' => $validated['expense_date'],
                'payment_source' => $validated['payment_source'],
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_OVERHEAD'], 422);
        }

        return response()->json($expense->load('operatingUnit'), 201);
    }

    public function allocate(Request $request, string $id): JsonResponse
    {
        $expense = OverheadExpense::withoutGlobalScopes()->findOrFail($id);

        $validated = $request->validate([
            'method' => 'sometimes|nullable|string|in:even_split,usage_based,headcount_based,manual_percentage',
            'usage' => 'sometimes|array',
            'usage.*' => 'numeric|min:0',
            'percentages' => 'sometimes|array',
            'percentages.*' => 'numeric|min:0',
        ]);

        try {
            $expense = $this->overheadService->allocate(
                $expense,
                isset($validated['method']) ? AllocationMethod::from($validated['method']) : null,
                $validated['usage'] ?? [],
                $validated['percentages'] ?? [],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_ALLOCATION'], 422);
        }

        return response()->json($expense);
    }

    public function rules(): JsonResponse
    {
        return response()->json([
            'data' => OverheadAllocationRule::latest()->get(),
        ]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'method' => 'required|string|in:even_split,usage_based,headcount_based,manual_percentage',
            'percentages' => 'required_if:method,manual_percentage|nullable|array',
            'percentages.*' => 'numeric|min:0',
        ]);

        $companyId = Company::query()->value('id');

        if ($companyId === null) {
            return response()->json(['message' => 'No company exists.'], 422);
        }

        // One active rule at a time: the new rule supersedes the rest.
        OverheadAllocationRule::where('company_id', $companyId)->update(['is_active' => false]);

        $rule = OverheadAllocationRule::create([
            'company_id' => $companyId,
            'method' => $validated['method'],
            'percentages' => $validated['percentages'] ?? null,
            'is_active' => true,
        ]);

        return response()->json($rule, 201);
    }
}
