<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\InventoryEventType;
use App\Http\Controllers\Controller;
use App\Models\OperatingUnit;
use App\Models\OperatingUnitAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperatingUnitAccountController extends Controller
{
    /**
     * List every per-event chart-of-accounts override for this unit, one row
     * per (event_type). Sorted by event order so the FE can render a stable
     * list.
     */
    public function index(string $id): JsonResponse
    {
        $unit = OperatingUnit::with(['accounts.account'])->findOrFail($id);

        $rows = InventoryEventType::cases();
        $byType = $unit->accounts->keyBy(fn ($r) => $r->event_type->value);

        $payload = [];
        foreach ($rows as $case) {
            $row = $byType->get($case->value);
            $payload[] = [
                'id' => $row?->id,
                'event_type' => $case->value,
                'event_label' => $case->arabicLabel(),
                'account' => $row?->account ? [
                    'id' => $row->account->id,
                    'account_code' => $row->account->account_code,
                    'name' => $row->account->name,
                    'type' => $row->account->type,
                    'currency' => $row->account->currency,
                ] : null,
            ];
        }

        return response()->json([
            'data' => $payload,
            'meta' => [
                'linked_count' => $unit->accounts()->count(),
                'total_events' => count($rows),
            ],
        ]);
    }

    /**
     * Upsert one (unit, event_type) → account mapping. The unique index
     * enforces one row per (unit, event) so we always update-or-create.
     */
    public function upsert(Request $request, string $id): JsonResponse
    {
        $unit = OperatingUnit::findOrFail($id);

        $validated = $request->validate([
            'event_type' => ['required', 'string', 'in:'.implode(',', array_column(InventoryEventType::cases(), 'value'))],
            'account_id' => ['required', 'uuid', 'exists:accounts,id'],
        ]);

        $event = InventoryEventType::from($validated['event_type']);

        $row = OperatingUnitAccount::updateOrCreate(
            [
                'operating_unit_id' => $unit->id,
                'event_type' => $event->value,
            ],
            [
                'account_id' => $validated['account_id'],
            ],
        );

        $row->load('account');

        return response()->json([
            'data' => [
                'id' => $row->id,
                'event_type' => $row->event_type->value,
                'event_label' => $row->event_type->arabicLabel(),
                'account' => $row->account ? [
                    'id' => $row->account->id,
                    'account_code' => $row->account->account_code,
                    'name' => $row->account->name,
                    'type' => $row->account->type,
                    'currency' => $row->account->currency,
                ] : null,
            ],
            'message' => 'تم ربط الحساب بنجاح',
        ]);
    }

    /**
     * Remove one (unit, event_type) override. Returns 204-style empty JSON
     * body for parity with the rest of the API.
     */
    public function destroy(string $id, string $rowId): JsonResponse
    {
        $unit = OperatingUnit::findOrFail($id);

        $row = OperatingUnitAccount::where('operating_unit_id', $unit->id)
            ->whereKey($rowId)
            ->firstOrFail();

        $row->delete();

        return response()->json(['message' => 'تم حذف الربط']);
    }
}
