<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PayableSettlement;
use App\Services\PayableSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class PayableSettlementController extends Controller
{
    public function __construct(
        private readonly PayableSettlementService $settlementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PayableSettlement::with(['operatingUnit', 'settledBy'])->latest();

        if ($request->filled('account_code')) {
            $query->where('account_code', $request->query('account_code'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    /**
     * The three settleable payables and what each currently carries — the
     * treasury screen's "what do we owe" strip.
     */
    public function outstanding(): JsonResponse
    {
        return response()->json([
            'data' => collect(['21', '221', '23'])->map(fn (string $code) => [
                'account_code' => $code,
                'outstanding' => $this->settlementService->outstanding($code),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Same accountability bar as manual journal entries: money leaves
        // the company here.
        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager') && ! $user->hasRole('treasury-officer')) {
            return response()->json([
                'message' => 'Only an accounting manager, treasury officer, or the owner can settle payables.',
                'code' => 'SETTLEMENT_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'account_code' => 'required|string|in:21,221,23',
            'amount' => 'required|numeric|min:0.0001',
            'reference' => 'nullable|string|max:255',
            'operating_unit_id' => 'sometimes|nullable|uuid|exists:operating_units,id',
        ]);

        $companyId = Company::query()->value('id');

        if ($companyId === null) {
            return response()->json(['message' => 'No company exists.'], 422);
        }

        try {
            $settlement = $this->settlementService->settle([
                'company_id' => $companyId,
                'account_code' => $validated['account_code'],
                'amount' => (float) $validated['amount'],
                'reference' => $validated['reference'] ?? null,
                'operating_unit_id' => $validated['operating_unit_id'] ?? null,
                'settled_by_user_id' => $user->id,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_SETTLEMENT'], 422);
        }

        return response()->json($settlement->load(['operatingUnit', 'settledBy']), 201);
    }
}
