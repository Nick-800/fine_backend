<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\GoodsReceiptResource;
use App\Models\ImportOrder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class GoodsReceiptController extends Controller
{
    public function index(string $orderId): AnonymousResourceCollection
    {
        $order = ImportOrder::with('goodsReceipt')->findOrFail($orderId);

        return GoodsReceiptResource::collection($order->goodsReceipt ? [$order->goodsReceipt] : []);
    }
}
