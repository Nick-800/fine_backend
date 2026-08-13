<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\FixedAssetStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Services\FixedAssetService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class FixedAssetController extends Controller
{
    public function __construct(
        private readonly FixedAssetService $assetService,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = FixedAsset::with('operatingUnit')->orderBy('asset_code');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            FixedAsset::with(['operatingUnit', 'depreciationEntries' => fn ($q) => $q->orderByDesc('period')])
                ->findOrFail($id)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'asset_code' => 'required|string|max:50|unique:fixed_assets,asset_code',
            'acquisition_cost' => 'required|numeric|min:0.0001',
            'acquisition_date' => 'required|date|before_or_equal:today',
            'depreciation_method' => 'required|string|in:straight_line,declining_balance',
            'useful_life_years' => 'required|integer|min:1|max:100',
            'salvage_value' => 'sometimes|numeric|min:0',
            'payment_source' => 'required|string|in:cash,payable',
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
            $asset = $this->assetService->acquire([
                'company_id' => $companyId,
                'operating_unit_id' => $unitId,
                'name' => $validated['name'],
                'asset_code' => $validated['asset_code'],
                'acquisition_cost' => (float) $validated['acquisition_cost'],
                'acquisition_date' => $validated['acquisition_date'],
                'depreciation_method' => $validated['depreciation_method'],
                'useful_life_years' => (int) $validated['useful_life_years'],
                'salvage_value' => (float) ($validated['salvage_value'] ?? 0),
                'payment_source' => $validated['payment_source'],
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_ASSET'], 422);
        }

        return response()->json($asset->load('operatingUnit'), 201);
    }

    public function depreciate(Request $request, string $id): JsonResponse
    {
        $asset = FixedAsset::findOrFail($id);

        $request->validate([
            'period' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $entry = $this->assetService->depreciateForPeriod($asset, $request->input('period'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_DEPRECIATION'], 422);
        }

        if ($entry === null) {
            return response()->json([
                'message' => 'Nothing to post: the period has already run, the asset is paused, or it is fully depreciated.',
                'code' => 'DEPRECIATION_SKIPPED',
            ], 422);
        }

        return response()->json([
            'message' => 'Depreciation posted.',
            'data' => $entry,
            'asset' => $asset->refresh(),
        ], 201);
    }

    public function dispose(Request $request, string $id): JsonResponse
    {
        $asset = FixedAsset::findOrFail($id);

        $validated = $request->validate([
            'proceeds' => 'required|numeric|min:0',
            'disposed_at' => 'sometimes|nullable|date|before_or_equal:today',
        ]);

        try {
            $asset = $this->assetService->dispose(
                $asset,
                (float) $validated['proceeds'],
                $validated['disposed_at'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_DISPOSAL'], 422);
        }

        return response()->json(['message' => 'Asset disposed.', 'data' => $asset]);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $asset = FixedAsset::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:active,under_maintenance',
        ]);

        try {
            $asset = $this->assetService->transition($asset, FixedAssetStatus::from($validated['status']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_ASSET_TRANSITION'], 422);
        }

        return response()->json(['message' => 'Status updated.', 'data' => $asset]);
    }

    public function depreciationSchedule(string $id): JsonResponse
    {
        $asset = FixedAsset::findOrFail($id);

        return response()->json([
            'asset_id' => $asset->id,
            'book_value' => $asset->bookValue(),
            'rows' => $this->assetService->schedule($asset),
        ]);
    }
}
