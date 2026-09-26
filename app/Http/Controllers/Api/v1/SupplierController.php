<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreSupplierRequest;
use App\Http\Resources\v1\SupplierResource;
use App\Models\Supplier;
use App\Services\CoaLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class SupplierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Supplier::with(['operatingUnit', 'account']);

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        return SupplierResource::collection($query->latest()->get());
    }

    public function store(StoreSupplierRequest $request, CoaLinkService $coaLinkService): JsonResponse
    {
        $supplier = DB::transaction(function () use ($request, $coaLinkService) {
            $accountId = $coaLinkService->resolveOrProvisionAccount(
                $request->only(['coa_action', 'account_id', 'new_account']),
                $request->user()?->company_id
            );

            $data = $request->only('operating_unit_id', 'name', 'contact', 'default_currency', 'address');
            $data['account_id'] = $accountId;

            return Supplier::create($data)->load(['operatingUnit', 'account']);
        });

        return (new SupplierResource($supplier))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): SupplierResource
    {
        $supplier = Supplier::with(['operatingUnit', 'account'])->findOrFail($id);

        return new SupplierResource($supplier);
    }

    public function update(Request $request, string $id, CoaLinkService $coaLinkService): SupplierResource
    {
        $supplier = Supplier::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact' => 'nullable|string|max:255',
            'default_currency' => 'sometimes|string|size:3',
            'address' => 'nullable|string',
            'account_id' => 'nullable|uuid|exists:accounts,id',
            'coa_action' => 'nullable|string|in:create_new,link_existing,none',
            'new_account' => 'nullable|array',
            'new_account.parent_account_id' => 'required_if:coa_action,create_new|nullable|uuid|exists:accounts,id',
            'new_account.account_code' => 'required_if:coa_action,create_new|nullable|string|max:50|unique:accounts,account_code',
            'new_account.name' => 'required_if:coa_action,create_new|nullable|string|max:255',
            'new_account.currency' => 'nullable|string|size:3',
        ]);

        $accountId = $supplier->account_id;
        if ($request->has('coa_action') || $request->has('account_id')) {
            $accountId = $coaLinkService->resolveOrProvisionAccount(
                $request->only(['coa_action', 'account_id', 'new_account']),
                $request->user()?->company_id
            );
        }

        $data = $request->only('name', 'contact', 'default_currency', 'address');
        $data['account_id'] = $accountId;

        $supplier->update($data);

        return new SupplierResource($supplier->load(['operatingUnit', 'account']));
    }

    public function destroy(string $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->delete();

        return response()->json([
            'message' => 'Supplier deleted successfully.',
        ]);
    }
}
