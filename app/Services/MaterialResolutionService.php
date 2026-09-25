<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CutterWorkOrder;
use App\Models\ImportOrder;
use App\Models\MaterialRequest;
use App\Models\ProductionBatch;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MaterialResolutionService
{
    /**
     * Mark the request in_progress — typically called when the fulfilling
     * module picks it up.
     */
    public function markInProgress(MaterialRequest $request): MaterialRequest
    {
        if ($request->status !== MaterialRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(
                "Material request {$request->id} is {$request->status}; only pending requests can be started."
            );
        }

        $request->status = MaterialRequest::STATUS_IN_PROGRESS;
        $request->save();

        return $request;
    }

    /**
     * Mark the request fulfilled by the given model.
     */
    public function markFulfilled(MaterialRequest $request, EloquentModel $fulfillingModel): MaterialRequest
    {
        return DB::transaction(function () use ($request, $fulfillingModel) {
            if ($request->status === MaterialRequest::STATUS_FULFILLED) {
                return $request;
            }

            if (! in_array($request->status, [MaterialRequest::STATUS_PENDING, MaterialRequest::STATUS_IN_PROGRESS], true)) {
                throw new InvalidArgumentException(
                    "Material request {$request->id} is {$request->status}; cannot fulfill."
                );
            }

            $request->status = MaterialRequest::STATUS_FULFILLED;
            $request->fulfilled_by_type = $this->mapFulfilledByType($fulfillingModel);
            $request->fulfilled_by_id = $fulfillingModel->getKey();
            $request->fulfilled_at = now();
            $request->save();

            return $request->refresh();
        });
    }

    /**
     * Cancel an open request.
     */
    public function cancel(MaterialRequest $request): MaterialRequest
    {
        if (! $request->isOpen()) {
            throw new InvalidArgumentException(
                "Material request {$request->id} is {$request->status}; only open requests can be cancelled."
            );
        }

        $request->status = MaterialRequest::STATUS_CANCELLED;
        $request->save();

        return $request;
    }

    /**
     * Map a fulfilling model class to the stable snake_case token we store
     * on the material_request. Covers the known models and falls back to
     * snake_case for any future additions.
     */
    private function mapFulfilledByType(EloquentModel $model): string
    {
        return match ($model::class) {
            CutterWorkOrder::class => 'cutter_work_order',
            ProductionBatch::class => 'production_batch',
            ImportOrder::class => 'procurement_request',
            default => strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', class_basename($model)) ?? ''),
        };
    }
}
