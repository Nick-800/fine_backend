<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PayableSettlement;
use App\Services\PayableSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class PayableSettlementController extends Controller
{
    public function __construct(
        private readonly PayableSettlementService $settlementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PayableSettlement::with(['operatingUnit', 'settledBy', 'cheque'])->latest();

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
            'data' => collect(PayableSettlementService::SETTLEABLE)->map(fn (string $code) => [
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

        $isCheque = $request->input('method') === 'cheque';
        $rules = [
            'account_code' => ['required', 'string', Rule::in(PayableSettlementService::SETTLEABLE)],
            'amount' => 'required|numeric|min:0.0001',
            'reference' => 'nullable|string|max:255',
            'operating_unit_id' => 'sometimes|nullable|uuid|exists:operating_units,id',
            'method' => ['sometimes', 'string', Rule::in(['cash', 'cheque'])],
        ];

        if ($isCheque) {
            $rules['cash_account_id'] = 'sometimes|nullable|uuid|exists:cash_accounts,id';
            $rules['cheque'] = 'required|array';
            $rules['cheque.cash_account_id'] = 'required|uuid|exists:cash_accounts,id';
            $rules['cheque.cheque_no'] = 'required|string|max:50';
            $rules['cheque.due_date'] = 'required|date';
            $rules['cheque.issue_date'] = 'sometimes|nullable|date';
            $rules['cheque.payee_name'] = 'sometimes|nullable|string|max:150';
        } else {
            $rules['cash_account_id'] = 'required|uuid|exists:cash_accounts,id';
        }

        $validated = $request->validate($rules);

        $companyId = Company::query()->value('id');

        if ($companyId === null) {
            return response()->json(['message' => 'No company exists.'], 422);
        }

        try {
            $settlement = $this->settlementService->settle([
                'company_id' => $companyId,
                'account_code' => $validated['account_code'],
                'amount' => (float) $validated['amount'],
                'method' => $validated['method'] ?? 'cash',
                'cash_account_id' => $validated['cash_account_id'] ?? null,
                'cheque' => $validated['cheque'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'operating_unit_id' => $validated['operating_unit_id'] ?? null,
                'settled_by_user_id' => $user->id,
                'user' => $user,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_SETTLEMENT'], 422);
        }

        return response()->json($settlement->load(['operatingUnit', 'settledBy', 'cheque']), 201);
    }
}
