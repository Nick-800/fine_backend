<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InventoryAttributeDefinition;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\StockAdjustmentRequest;
use App\Models\StockLot;
use App\Models\TankStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use Illuminate\Database\Seeder;

/**
 * Inventory master data and opening stock.
 *
 * Chemicals mirror the ones on the real production report — polyol grades, TDI,
 * amine, T-9, silicone, methylene chloride — so the seeded data exercises the
 * same shapes the floor actually works with.
 */
class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $foamUnit = OperatingUnit::where('unit_type', 'foam_manufactory')->first()
            ?? OperatingUnit::where('name', 'like', '%Foam%')->first()
            ?? OperatingUnit::first();

        $foamWarehouse = Warehouse::where('operating_unit_id', $foamUnit->id)->first();

        // ---------------------------------------------------------------
        // Categories and the shared attribute library
        // ---------------------------------------------------------------
        $chemicalCategory = ItemCategory::create([
            'operating_unit_id' => null, // shared across units
            'name' => 'Chemicals',
            'code' => 'CAT-CHEM',
            'description' => 'Bulk polyols, isocyanates and additives.',
        ]);

        $foamCategory = ItemCategory::create([
            'operating_unit_id' => null,
            'name' => 'Foam Products',
            'code' => 'CAT-FOAM',
            'description' => 'Serialized foam blocks, slices and byproduct fill.',
        ]);

        $containerCategory = ItemCategory::create([
            'operating_unit_id' => null,
            'name' => 'Containers',
            'code' => 'CAT-CONT',
            'description' => 'Returnable barrels and pallets.',
        ]);

        $attributes = [
            ['name' => 'Pressure', 'slug' => 'pressure_kpa', 'data_type' => 'number', 'unit_of_measure' => 'kPa', 'sort_order' => 1],
            ['name' => 'Density', 'slug' => 'density_kg_m3', 'data_type' => 'number', 'unit_of_measure' => 'kg/m³', 'sort_order' => 2],
            ['name' => 'Colour', 'slug' => 'colour', 'data_type' => 'select', 'options' => ['white', 'orange', 'green', 'blue', 'brown'], 'sort_order' => 3],
            ['name' => 'Purity', 'slug' => 'purity_pct', 'data_type' => 'number', 'unit_of_measure' => '%', 'sort_order' => 4],
            ['name' => 'Viscosity', 'slug' => 'viscosity_cps', 'data_type' => 'number', 'unit_of_measure' => 'cPs', 'sort_order' => 5],
        ];

        $attributeModels = [];
        foreach ($attributes as $attribute) {
            $attributeModels[$attribute['slug']] = InventoryAttributeDefinition::create($attribute);
        }

        // ---------------------------------------------------------------
        // Returnable containers — these are stock in their own right (INV-10)
        // ---------------------------------------------------------------
        $emptyBarrel40 = InventoryItem::create([
            'category_id' => $containerCategory->id,
            'name' => 'Empty Barrel 40L',
            'sku' => 'BARREL-40-EMPTY',
            'item_type' => 'barrel',
            'unit_of_measure' => 'each',
        ]);

        $emptyBarrel200 = InventoryItem::create([
            'category_id' => $containerCategory->id,
            'name' => 'Empty Barrel 200L',
            'sku' => 'BARREL-200-EMPTY',
            'item_type' => 'barrel',
            'unit_of_measure' => 'each',
        ]);

        InventoryItem::create([
            'category_id' => $containerCategory->id,
            'name' => 'Pallet',
            'sku' => 'PALLET-STD',
            'item_type' => 'pallet',
            'unit_of_measure' => 'each',
        ]);

        // ---------------------------------------------------------------
        // Chemicals. container_capacity is what makes the barrel-to-tank
        // cascade work: it is how many litres one barrel holds.
        // ---------------------------------------------------------------
        $chemicals = [
            ['name' => 'Polyol 15%', 'sku' => 'CHEM-POLYOL-15', 'capacity' => 200, 'empty' => $emptyBarrel200, 'cost' => 3.10, 'barrels' => 6],
            ['name' => 'Polyol 45%', 'sku' => 'CHEM-POLYOL-45', 'capacity' => 200, 'empty' => $emptyBarrel200, 'cost' => 3.45, 'barrels' => 6],
            ['name' => 'TDI (Sabec)', 'sku' => 'CHEM-TDI-SABEC', 'capacity' => 200, 'empty' => $emptyBarrel200, 'cost' => 5.20, 'barrels' => 8],
            ['name' => 'Amine A33', 'sku' => 'CHEM-AMINE-A33', 'capacity' => 40, 'empty' => $emptyBarrel40, 'cost' => 12.00, 'barrels' => 2],
            ['name' => 'Catalyst T-9', 'sku' => 'CHEM-T9', 'capacity' => 40, 'empty' => $emptyBarrel40, 'cost' => 18.50, 'barrels' => 2],
            ['name' => 'Silicone JC-7858', 'sku' => 'CHEM-SIL-JC7858', 'capacity' => 40, 'empty' => $emptyBarrel40, 'cost' => 9.75, 'barrels' => 3],
            ['name' => 'Methylene Chloride', 'sku' => 'CHEM-MC', 'capacity' => 200, 'empty' => $emptyBarrel200, 'cost' => 2.40, 'barrels' => 3],
        ];

        foreach ($chemicals as $index => $chemical) {
            $item = InventoryItem::create([
                'category_id' => $chemicalCategory->id,
                'name' => $chemical['name'],
                'sku' => $chemical['sku'],
                'item_type' => 'raw_material',
                'unit_of_measure' => 'liter',
                'primary_uom' => 'barrel',
                'secondary_uom' => 'liter',
                'container_capacity' => $chemical['capacity'],
                'empty_container_item_id' => $chemical['empty']->id,
            ]);

            $item->attributeDefinitions()->sync([
                $attributeModels['purity_pct']->id,
                $attributeModels['viscosity_cps']->id,
            ]);

            // Opening stock: full barrels, so quantity and container count agree.
            StockLot::create([
                'inventory_item_id' => $item->id,
                'warehouse_id' => $foamWarehouse->id,
                'lot_number' => sprintf('LOT-%s-%03d', $chemical['sku'], $index + 1),
                'quantity' => $chemical['barrels'] * $chemical['capacity'],
                'container_quantity' => $chemical['barrels'],
                'unit_cost' => $chemical['cost'],
                'status' => 'available',
                'attribute_values' => ['purity_pct' => 99.2],
            ]);
        }

        // ---------------------------------------------------------------
        // Foam outputs
        // ---------------------------------------------------------------
        $foamBlock = InventoryItem::create([
            'category_id' => $foamCategory->id,
            'name' => 'Foam Block — White 12-14',
            'sku' => 'BLOCK-WHITE-1214',
            'item_type' => 'foam_block',
            'unit_of_measure' => 'm3',
        ]);

        $foamBlock->attributeDefinitions()->sync([
            $attributeModels['density_kg_m3']->id,
            $attributeModels['colour']->id,
        ]);

        InventoryItem::create([
            'category_id' => $foamCategory->id,
            'name' => 'Foam Scrap Fill',
            'sku' => 'SCRAP-FILL',
            'item_type' => 'byproduct_fill',
            'unit_of_measure' => 'm3',
        ]);

        InventoryItem::create([
            'category_id' => $foamCategory->id,
            'name' => 'Foam Slice',
            'sku' => 'SLICE-STD',
            'item_type' => 'slice',
            'unit_of_measure' => 'each',
        ]);

        // ---------------------------------------------------------------
        // Tanks, pre-charged so a foam run can be seeded on top
        // ---------------------------------------------------------------
        foreach (['CHEM-POLYOL-15', 'CHEM-POLYOL-45', 'CHEM-TDI-SABEC', 'CHEM-MC'] as $sku) {
            $item = InventoryItem::where('sku', $sku)->first();

            // Opening tank cost matches the barrels it was filled from, so the
            // weighted average starts consistent with stock on hand.
            $lotCost = (float) StockLot::where('inventory_item_id', $item->id)->value('unit_cost');

            TankStock::create([
                'chemical_inventory_item_id' => $item->id,
                'operating_unit_id' => $foamUnit->id,
                'quantity_on_hand' => 2500,
                'weighted_avg_unit_cost' => $lotCost,
            ]);
        }

        // ---------------------------------------------------------------
        // A pending adjustment so the approval queue is not empty
        // ---------------------------------------------------------------
        $requester = User::where('email', 'foam@erp.com')->first() ?? User::first();
        $polyolLot = StockLot::whereHas('inventoryItem', fn ($q) => $q->where('sku', 'CHEM-POLYOL-15'))->first();

        if ($polyolLot !== null && $requester !== null) {
            StockAdjustmentRequest::create([
                'operating_unit_id' => $foamUnit->id,
                'stock_lot_id' => $polyolLot->id,
                'reason_code' => 'spill_loss',
                'quantity_delta' => -12.5,
                'notes' => 'Spill during transfer to tank.',
                'status' => 'pending',
                'requested_by_user_id' => $requester->id,
            ]);
        }

        // ---------------------------------------------------------------
        // Ledger counterpart for everything created above. The lots and tank
        // charges were written directly (not through intake/refill), so the
        // opening value has to reach the books the same way an opening-balance
        // intake would: DR 1110 / CR 3100. Without this the stock ledger and
        // the GL disagree from the first request, and the foam consumption
        // posting (which credits 1110) would drive raw materials negative.
        // ---------------------------------------------------------------
        $lotValue = (float) StockLot::query()
            ->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as v')
            ->value('v');
        $tankValue = (float) TankStock::query()
            ->selectRaw('COALESCE(SUM(quantity_on_hand * weighted_avg_unit_cost), 0) as v')
            ->value('v');
        $openingValue = round($lotValue + $tankValue, 4);

        if ($openingValue > 0) {
            app(AccountingService::class)->postJournal(
                'Opening inventory balances (seed)',
                [
                    [
                        'account_code' => '111',
                        'debit' => $openingValue,
                        'operating_unit_id' => $foamUnit->id,
                        'memo' => 'seeded chemical lots + tank charges',
                    ],
                    [
                        'account_code' => '31',
                        'credit' => $openingValue,
                        'operating_unit_id' => $foamUnit->id,
                    ],
                ],
            );
        }
    }
}
