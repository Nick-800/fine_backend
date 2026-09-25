<?php

declare(strict_types=1);

namespace Database\Seeders\Dummy;

use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Adds finished-good inventory items, products, and the stock lots that let
 * sales and POS flows draw real quantities.
 *
 * Targets the furniture unit for products, and the foam and cutter units for
 * cut-piece lots so cutter work orders have something to operate on.
 */
class InventoryAndBomSeeder extends Seeder
{
    public function run(): void
    {
        $furnitureUnit = OperatingUnit::where('name', 'قسم الأثاث')->first()
            ?? OperatingUnit::first();
        $cutterUnit = OperatingUnit::where('name', 'قسم القص')->first()
            ?? OperatingUnit::first();
        $showroom = OperatingUnit::where('name', 'صالة العرض')->first()
            ?? OperatingUnit::first();

        if ($furnitureUnit === null || $cutterUnit === null || $showroom === null) {
            return;
        }

        $furnitureCategoryId = ItemCategory::where('code', 'CAT-FURN')->value('id');
        $foamCategoryId = ItemCategory::where('code', 'CAT-FOAM')->value('id');

        // Cut-piece inventory items in the cutter warehouse
        $cutPieceWarehouse = Warehouse::where('operating_unit_id', $cutterUnit->id)->first();
        $cutPieces = [
            ['name' => 'Cut Piece — Standard 100x60x10', 'code' => 'CUT-100-60-10', 'item_type' => 'cut_piece', 'unit_of_measure' => 'each'],
            ['name' => 'Cut Piece — Premium 120x60x12', 'code' => 'CUT-120-60-12', 'item_type' => 'cut_piece', 'unit_of_measure' => 'each'],
            ['name' => 'Cut Piece — Round 80 Diameter', 'code' => 'CUT-ROUND-80', 'item_type' => 'cut_piece', 'unit_of_measure' => 'each'],
            ['name' => 'Cut Piece — Bolster 50x20', 'code' => 'CUT-BOLSTER-5020', 'item_type' => 'cut_piece', 'unit_of_measure' => 'each'],
        ];
        foreach ($cutPieces as $c) {
            $item = InventoryItem::firstOrCreate(
                ['code' => $c['code']],
                $c + ['category_id' => $foamCategoryId],
            );

            for ($lot = 1; $lot <= 2; $lot++) {
                StockLot::firstOrCreate(
                    ['lot_number' => 'LOT-'.$c['code'].'-'.$lot],
                    [
                        'id' => (string) Str::uuid(),
                        'inventory_item_id' => $item->id,
                        'warehouse_id' => $cutPieceWarehouse->id,
                        'quantity' => fake()->numberBetween(20, 80),
                        'unit_cost' => fake()->randomFloat(4, 8, 35),
                        'status' => 'available',
                    ],
                );
            }
        }

        // Furniture finished-goods in the showroom warehouse
        $showroomWarehouse = Warehouse::where('operating_unit_id', $showroom->id)->first()
            ?? Warehouse::where('operating_unit_id', $furnitureUnit->id)->first();

        $finishedGoods = [
            ['name' => 'Foam Mattress — Queen', 'code' => 'FG-MATTRESS-Q', 'item_type' => 'finished_good', 'unit_of_measure' => 'each'],
            ['name' => 'Foam Mattress — King', 'code' => 'FG-MATTRESS-K', 'item_type' => 'finished_good', 'unit_of_measure' => 'each'],
            ['name' => 'Cushion Set — Standard', 'code' => 'FG-CUSHION-SET', 'item_type' => 'finished_good', 'unit_of_measure' => 'set'],
            ['name' => 'Sofa Section — Left', 'code' => 'FG-SOFA-LEFT', 'item_type' => 'finished_good', 'unit_of_measure' => 'each'],
            ['name' => 'Bolster Pillow', 'code' => 'FG-BOLSTER', 'item_type' => 'finished_good', 'unit_of_measure' => 'each'],
            ['name' => 'Bed Base — Queen', 'code' => 'FG-BASE-Q', 'item_type' => 'finished_good', 'unit_of_measure' => 'each'],
        ];
        foreach ($finishedGoods as $g) {
            $item = InventoryItem::firstOrCreate(
                ['code' => $g['code']],
                $g + ['category_id' => $furnitureCategoryId],
            );

            StockLot::firstOrCreate(
                ['lot_number' => 'LOT-'.$g['code'].'-A'],
                [
                    'id' => (string) Str::uuid(),
                    'inventory_item_id' => $item->id,
                    'warehouse_id' => $showroomWarehouse->id,
                    'quantity' => fake()->numberBetween(5, 40),
                    'unit_cost' => fake()->randomFloat(4, 80, 1200),
                    'status' => 'available',
                ],
            );
        }

        // Products in the furniture unit
        $sliceItem = InventoryItem::where('code', 'SLICE-STD')->first();

        if ($sliceItem === null) {
            return;
        }

        $productSpecs = [
            ['name' => 'Queen Mattress — Premium', 'sku' => 'PROD-Q-MATT-PREM', 'markup' => 1.35],
            ['name' => 'King Mattress — Luxury', 'sku' => 'PROD-K-MATT-LUX', 'markup' => 1.45],
            ['name' => 'Standard Cushion Set', 'sku' => 'PROD-CUSH-STD', 'markup' => 1.25],
            ['name' => 'Left Sofa Section', 'sku' => 'PROD-SOFA-L', 'markup' => 1.40],
            ['name' => 'Bolster Pillow', 'sku' => 'PROD-BOLSTER', 'markup' => 1.30],
            ['name' => 'Bed Base Queen', 'sku' => 'PROD-BASE-Q', 'markup' => 1.20],
        ];

        foreach ($productSpecs as $spec) {
            $finishedSku = 'FG-'.str_replace('PROD-', '', $spec['sku']);
            $inventoryItem = InventoryItem::where('code', 'FG-'.str_replace('PROD-', '', $spec['sku']))->first()
                ?? InventoryItem::where('code', $spec['sku'])->first()
                ?? $sliceItem;

            Product::firstOrCreate(
                ['sku' => $spec['sku']],
                [
                    'id' => (string) Str::uuid(),
                    'operating_unit_id' => $furnitureUnit->id,
                    'inventory_item_id' => $inventoryItem->id,
                    'name' => $spec['name'],
                    'description' => 'Dummy product seeded by DummyDataSeeder.',
                    'markup_factor' => $spec['markup'],
                ],
            );
        }
    }
}
