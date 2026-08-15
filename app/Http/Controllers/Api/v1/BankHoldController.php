<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\BankHoldResource;
use App\Models\BankHold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BankHoldController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BankHold::with('paymentRequest');

        return BankHoldResource::collection($query->latest()->get());
    }

    /**
     * Holds belonging to one import order's payment requests.
     */
    public function forOrder(string $id): AnonymousResourceCollection
    {
        $holds = BankHold::with('paymentRequest')
            ->whereHas('paymentRequest', fn ($q) => $q->where('import_order_id', $id))
            ->latest()
            ->get();

        return BankHoldResource::collection($holds);
    }
}
