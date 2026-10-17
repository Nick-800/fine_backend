<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ReferenceLookup;
use App\Support\ReferenceGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ReferenceLookupController extends Controller
{
    public function index(Request $request, string $category): JsonResponse
    {
        $query = ReferenceLookup::query()->where('category', $category);

        if ($request->has('search') && filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request, string $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('reference_lookups', 'code')->where('category', $category),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'fields' => ['nullable', 'array'],
        ]);

        $item = ReferenceLookup::create([
            ...$validated,
            'category' => $category,
        ]);

        return response()->json($item, 201);
    }

    public function show(string $category, int|string $id): JsonResponse
    {
        $item = ReferenceLookup::query()
            ->where('category', $category)
            ->findOrFail($id);

        return response()->json($item);
    }

    public function update(Request $request, string $category, int|string $id): JsonResponse
    {
        $item = ReferenceLookup::query()
            ->where('category', $category)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('reference_lookups', 'code')
                    ->where('category', $category)
                    ->ignore($item->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'fields' => ['nullable', 'array'],
        ]);

        $item->update($validated);

        return response()->json($item);
    }

    public function destroy(string $category, int|string $id): JsonResponse
    {
        $item = ReferenceLookup::query()
            ->where('category', $category)
            ->findOrFail($id);

        if ($category === 'currencies' && ($refusal = $this->currencyDeleteRefusal($item)) !== null) {
            return $refusal;
        }

        $item->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    /**
     * BR-CUR-002: the base currency, and any currency already used on accounts,
     * treasuries or documents, cannot be deleted.
     */
    private function currencyDeleteRefusal(ReferenceLookup $currency): ?JsonResponse
    {
        $code = strtoupper((string) ($currency->code ?: $currency->name));
        $baseCurrency = strtoupper((string) (Company::query()->value('default_currency') ?? 'LYD'));

        if ($code === $baseCurrency) {
            return response()->json([
                'message' => "{$code} is the base currency and cannot be deleted.",
                'code' => 'BASE_CURRENCY',
            ], 422);
        }

        $inUse = ReferenceGuard::firstReference([
            'accounts' => 'currency',
            'cash_accounts' => 'currency',
            'operating_units' => 'currency',
            'purchase_orders' => 'currency',
            'purchase_order_items' => 'currency',
            'landed_cost_lines' => 'currency',
            'overhead_expenses' => 'currency',
            'fx_rates' => ['from_currency', 'to_currency'],
        ], $code);

        if ($inUse === null) {
            return null;
        }

        return response()->json([
            'message' => "Currency {$code} is in use ({$inUse}) and cannot be deleted. Deactivate it instead.",
            'code' => 'CURRENCY_IN_USE',
            'referenced_by' => $inUse,
        ], 422);
    }

    public function toggleActive(string $category, int|string $id): JsonResponse
    {
        $item = ReferenceLookup::query()
            ->where('category', $category)
            ->findOrFail($id);

        $item->update([
            'is_active' => ! $item->is_active,
        ]);

        return response()->json($item);
    }
}
