<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\CashAccountResource;
use App\Models\CashAccount;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CashAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CashAccount::with('account');

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if (filled($request->query('kind'))) {
            $query->where('kind', $request->query('kind'));
        }

        return CashAccountResource::collection($query->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'operating_unit_id' => 'required|uuid|exists:operating_units,id',
            'name' => 'required|string|max:255',
            'kind' => 'sometimes|string|in:cash,bank',
            'account_id' => 'nullable|uuid|exists:accounts,id',
            'currency' => 'sometimes|string|size:3',
            'balance' => 'sometimes|numeric',
        ]);

        $context = app(CurrentUnitContext::class);
        $unitId = $request->input('operating_unit_id');
        if ($context->hasUnit()) {
            if ($unitId && $unitId !== $context->id()) {
                throw new AccessDeniedHttpException('Cannot create cash account in another operating unit.');
            }
            $unitId = $context->id();
        }

        $account = CashAccount::create([
            'operating_unit_id' => $unitId,
            'name' => $request->input('name'),
            'kind' => $request->input('kind', CashAccount::KIND_CASH),
            'account_id' => $request->input('account_id'),
            'currency' => strtoupper($request->input('currency', 'LYD')),
            'balance' => $request->input('balance', 0),
        ]);

        return (new CashAccountResource($account->load('account')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Rename a treasury, change its kind, or link it to its ledger account.
     */
    public function update(Request $request, string $id): CashAccountResource
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'kind' => 'sometimes|string|in:cash,bank',
            'account_id' => 'sometimes|nullable|uuid|exists:accounts,id',
        ]);

        $account = CashAccount::findOrFail($id);
        $account->update($validated);

        return new CashAccountResource($account->load('account'));
    }
}
