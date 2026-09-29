<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\InventoryEventType;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryItemAccount;
use App\Models\ItemCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryItem::with(['category', 'accounts.account']);

        if ($request->has('category_id') && filled($request->query('category_id'))) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->has('item_type') && filled($request->query('item_type'))) {
            $query->where('item_type', $request->query('item_type'));
        }

        if ($request->has('search') && filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // The POS picker shows what is on this unit's shelves.
        if ($request->boolean('with_stock')) {
            $query->withSum(['stockLots as available_quantity' => fn ($q) => $q->where('status', 'available')], 'quantity');
        }

        return response()->json($query->orderBy('name')->paginate(min($request->integer('per_page', 15), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        if (empty($request->input('item_type')) && $request->filled('category_id')) {
            $category = ItemCategory::find($request->input('category_id'));
            if ($category && ! empty($category->item_type)) {
                $request->merge(['item_type' => $category->item_type]);
            }
        }

        $validated = $request->validate([
            'category_id' => ['nullable', 'uuid', Rule::exists('item_categories', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', 'unique:inventory_items,code'],
            'item_type' => ['required', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'unit_of_measure' => ['required', 'string'],
            'primary_uom' => ['nullable', 'string'],
            'secondary_uom' => ['nullable', 'string'],
            // Container semantics: how much one container holds, and which item
            // represents it once empty.
            'container_capacity' => ['nullable', 'numeric', 'gt:0'],
            'empty_container_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
            'default_attributes' => ['nullable', 'array'],
            // Same column names as StockLot's own length_m/width_m/height_m,
            // which carries them per physical lot instead of per catalog item.
            'length_m' => ['nullable', 'numeric', 'min:0'],
            'width_m' => ['nullable', 'numeric', 'min:0'],
            'height_m' => ['nullable', 'numeric', 'min:0'],
            // POS starting price: per piece, or per m³ for sized pieces.
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'price_basis' => ['sometimes', 'string', 'in:unit,m3'],
        ]);

        $item = InventoryItem::create($validated);

        return response()->json($item->load(['category', 'accounts.account']), 201);
    }

    public function show(string $id): JsonResponse
    {
        $item = InventoryItem::with(['category', 'accounts.account'])->findOrFail($id);

        return response()->json($item);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);

        $validated = $request->validate([
            'category_id' => ['nullable', 'uuid', Rule::exists('item_categories', 'id')->whereNull('deleted_at')],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:100', "unique:inventory_items,code,{$id}"],
            'item_type' => ['sometimes', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'unit_of_measure' => ['sometimes', 'string'],
            'primary_uom' => ['nullable', 'string'],
            'secondary_uom' => ['nullable', 'string'],
            // Container semantics: how much one container holds, and which item
            // represents it once empty.
            'container_capacity' => ['nullable', 'numeric', 'gt:0'],
            'empty_container_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
            'default_attributes' => ['nullable', 'array'],
            'length_m' => ['nullable', 'numeric', 'min:0'],
            'width_m' => ['nullable', 'numeric', 'min:0'],
            'height_m' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'price_basis' => ['sometimes', 'string', 'in:unit,m3'],
        ]);

        $item->update($validated);

        return response()->json($item->load(['category', 'accounts.account']));
    }

    public function destroy(string $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Inventory item soft deleted.']);
    }

    /**
     * List every per-event chart-of-accounts override for this item, one row
     * per (event_type). Sorted by event order so the FE can render a stable
     * list.
     */
    public function accounts(string $id): JsonResponse
    {
        $item = InventoryItem::with(['accounts.account'])->findOrFail($id);

        $rows = InventoryEventType::cases();
        $byType = $item->accounts->keyBy(fn ($r) => $r->event_type->value);

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
                'linked_count' => $item->accounts()->count(),
                'total_events' => count($rows),
            ],
        ]);
    }

    /**
     * Upsert one (item, event_type) → account mapping. The unique index
     * enforces one row per (item, event) so we always update-or-create.
     */
    public function upsertAccount(Request $request, string $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);

        $validated = $request->validate([
            'event_type' => ['required', 'string', 'in:'.implode(',', array_column(InventoryEventType::cases(), 'value'))],
            'account_id' => ['required', 'uuid', 'exists:accounts,id'],
        ]);

        $event = InventoryEventType::from($validated['event_type']);

        $row = InventoryItemAccount::updateOrCreate(
            [
                'inventory_item_id' => $item->id,
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
     * Remove one (item, event_type) override. Returns 204-style empty JSON
     * body for parity with the rest of the API.
     */
    public function deleteAccount(string $id, string $rowId): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);

        $row = InventoryItemAccount::where('inventory_item_id', $item->id)
            ->whereKey($rowId)
            ->firstOrFail();

        $row->delete();

        return response()->json(['message' => 'تم حذف الربط']);
    }
}
