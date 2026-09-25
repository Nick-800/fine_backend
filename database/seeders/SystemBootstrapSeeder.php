<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ClientStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Enums\ImportOrderStatus;
use App\Enums\LandedCostType;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRoute;
use App\Enums\PayType;
use App\Enums\ProductionBatchStatus;
use App\Models\Account;
use App\Models\BankHold;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\Entity;
use App\Models\EntityContact;
use App\Models\EntityRole;
use App\Models\FxRate;
use App\Models\GoodsReceipt;
use App\Models\ImportOrder;
use App\Models\InventoryAttributeDefinition;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\JournalEntry;
use App\Models\LandedCostLine;
use App\Models\OperatingUnit;
use App\Models\PaymentRequest;
use App\Models\Permission;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\StockAdjustmentRequest;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\TankStock;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\ConsumptionReportService;
use App\Services\OperatingUnitService;
use App\Services\ProductionBatchService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the minimum data the system needs to function in a semi-production way.
 *
 * The chart of accounts is seeded empty — every account row exists but
 * carries no opening journal entries. Cash + raw-material inventory
 * openings are posted by seedCashAccountsAndOpeningBalance() and
 * seedInventoryMasterData() as part of their normal master-data flow.
 *
 * Idempotent: every record is keyed on a natural unique (slug, sku, email,
 * order_number, …) so re-running on an already-seeded database is a no-op.
 *
 * Order is deliberate:
 *   company → roles/permissions → chart → blueprints → units → master data
 *   → users → sample import flows → one foam run.
 */
class SystemBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCompany();
        $this->seedRolesAndPermissions();
        $this->seedChartOfAccounts();
        $this->seedBlueprints();
        $units = $this->seedOperatingUnits();
        $this->seedFxRates();
        $this->seedCashAccountsAndOpeningBalance($units['procurement']);

        $this->seedInventoryMasterData($units['foam']);
        $this->seedEntityBackedClient($units['procurement']);
        $this->seedEntityBackedEmployee($units['procurement']);
        $this->seedSuppliersAndImportOrders($units['procurement']);
        $this->seedStandardUsers($units);
        $this->seedOneFoamBatch($units['foam']);
    }

    private function seedCompany(): void
    {
        Company::firstOrCreate(
            ['name' => 'Fine'],
            [
                'id' => (string) Str::uuid(),
                'default_currency' => 'LYD',
                'overhead_absorption_enabled' => false,
                'transfer_pricing_mode' => 'at_cost',
                'timezone' => 'Africa/Tripoli',
            ],
        );
    }

    private function seedRolesAndPermissions(): void
    {
        $this->call(RoleSeeder::class);

        // Accounting/HR/treasury need to settle payables; the role template does
        // not include it by default.
        $settlePerm = Permission::firstOrCreate(
            ['slug' => 'settle-payables'],
            ['name' => 'Settle Payables', 'module' => 'treasury', 'action' => 'settle'],
        );
        foreach (['accounting-manager', 'hr-manager', 'treasury-officer'] as $slug) {
            $role = Role::where('slug', $slug)->first();
            if ($role !== null) {
                $role->permissions()->syncWithoutDetaching([$settlePerm->id]);
            }
        }
    }

    private function seedChartOfAccounts(): void
    {
        if (Account::count() === 0) {
            $this->call(ChartOfAccountsSeeder::class);
        }
    }

    private function seedBlueprints(): void
    {
        $this->call(BlueprintSeeder::class);
    }

    /**
     * @return array<string, OperatingUnit>
     */
    private function seedOperatingUnits(): array
    {
        $service = app(OperatingUnitService::class);

        $specs = [
            'procurement' => ['Foam Manufactory Blueprint', 'المشتريات والخزانة'],
            'foam' => ['Foam Manufactory Blueprint', 'مصنع الإسفنج'],
            'cutter' => ['Cutter Manufactory Blueprint', 'قسم القص'],
            'furniture' => ['Furniture Manufactory Blueprint', 'قسم الأثاث'],
            'showroom' => ['Store Blueprint', 'صالة العرض'],
        ];

        $resolved = [];
        foreach ($specs as $key => [$blueprintName, $unitName]) {
            $existing = OperatingUnit::where('name', $unitName)->first();
            if ($existing !== null) {
                $resolved[$key] = $existing;

                continue;
            }

            $blueprint = UnitBlueprint::where('name', $blueprintName)->firstOrFail();
            $resolved[$key] = $service->provision($blueprint, $unitName);
        }

        return $resolved;
    }

    private function seedFxRates(): void
    {
        FxRate::firstOrCreate(
            ['from_currency' => 'USD', 'to_currency' => 'LYD', 'captured_at' => now()->startOfDay()],
            ['id' => (string) Str::uuid(), 'rate' => 5.20],
        );
        FxRate::firstOrCreate(
            ['from_currency' => 'EUR', 'to_currency' => 'LYD', 'captured_at' => now()->startOfDay()],
            ['id' => (string) Str::uuid(), 'rate' => 5.65],
        );
    }

    private function seedCashAccountsAndOpeningBalance(OperatingUnit $unit): void
    {
        if (CashAccount::where('operating_unit_id', $unit->id)->doesntExist()) {
            CashAccount::create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $unit->id,
                'name' => 'Main Operating Cash Treasury',
                'currency' => 'LYD',
                'balance' => 1_500_000,
            ]);
            CashAccount::create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $unit->id,
                'name' => 'Foreign Exchange Clearing Safe',
                'currency' => 'USD',
                'balance' => 250_000,
            ]);
        }

        if (Account::where('account_code', '12')->exists() && ! $this->openingBalanceAlreadyPosted($unit)) {
            $openingValue = 1_500_000 + 250_000 * 5.20;
            app(AccountingService::class)->postJournal(
                'Opening treasury cash (bootstrap)',
                [
                    ['account_code' => '12', 'debit' => $openingValue, 'operating_unit_id' => $unit->id, 'memo' => 'LYD treasury + USD clearing safe at 5.20'],
                    ['account_code' => '31', 'credit' => $openingValue, 'operating_unit_id' => $unit->id],
                ],
            );
        }
    }

    private function openingBalanceAlreadyPosted(OperatingUnit $unit): bool
    {
        return JournalEntry::query()
            ->where('description', 'Opening treasury cash (bootstrap)')
            ->whereHas('lines', fn ($q) => $q->where('operating_unit_id', $unit->id))
            ->exists();
    }

    private function seedInventoryMasterData(OperatingUnit $foamUnit): void
    {
        // Categories
        $categories = [
            ['name' => 'Chemicals', 'code' => 'CAT-CHEM', 'description' => 'Bulk polyols, isocyanates and additives.'],
            ['name' => 'Foam Products', 'code' => 'CAT-FOAM', 'description' => 'Serialized foam blocks, slices and byproduct fill.'],
            ['name' => 'Containers', 'code' => 'CAT-CONT', 'description' => 'Returnable barrels and pallets.'],
            ['name' => 'Furniture', 'code' => 'CAT-FURN', 'description' => 'Assembled furniture finished goods.'],
            ['name' => 'Accessories', 'code' => 'CAT-ACC', 'description' => 'Hardware, fabric and trim.'],
        ];
        foreach ($categories as $row) {
            ItemCategory::firstOrCreate(['code' => $row['code']], $row + ['operating_unit_id' => null]);
        }

        // Attribute definitions
        $attributes = [
            ['name' => 'Pressure', 'slug' => 'pressure_kpa', 'data_type' => 'number', 'unit_of_measure' => 'kPa', 'sort_order' => 1],
            ['name' => 'Density', 'slug' => 'density_kg_m3', 'data_type' => 'number', 'unit_of_measure' => 'kg/m³', 'sort_order' => 2],
            ['name' => 'Colour', 'slug' => 'colour', 'data_type' => 'select', 'options' => ['white', 'orange', 'green', 'blue', 'brown'], 'sort_order' => 3],
            ['name' => 'Purity', 'slug' => 'purity_pct', 'data_type' => 'number', 'unit_of_measure' => '%', 'sort_order' => 4],
            ['name' => 'Viscosity', 'slug' => 'viscosity_cps', 'data_type' => 'number', 'unit_of_measure' => 'cPs', 'sort_order' => 5],
        ];
        foreach ($attributes as $attr) {
            InventoryAttributeDefinition::firstOrCreate(['slug' => $attr['slug']], $attr);
        }

        // Container items
        $containers = [
            ['name' => 'Empty Barrel 40L', 'sku' => 'BARREL-40-EMPTY', 'item_type' => 'barrel', 'unit_of_measure' => 'each'],
            ['name' => 'Empty Barrel 200L', 'sku' => 'BARREL-200-EMPTY', 'item_type' => 'barrel', 'unit_of_measure' => 'each'],
            ['name' => 'Pallet', 'sku' => 'PALLET-STD', 'item_type' => 'pallet', 'unit_of_measure' => 'each'],
        ];
        foreach ($containers as $c) {
            InventoryItem::firstOrCreate(
                ['sku' => $c['sku']],
                $c + ['category_id' => ItemCategory::where('code', 'CAT-CONT')->value('id')],
            );
        }

        $chemicalCategoryId = ItemCategory::where('code', 'CAT-CHEM')->value('id');
        $emptyBarrel40 = InventoryItem::where('sku', 'BARREL-40-EMPTY')->first();
        $emptyBarrel200 = InventoryItem::where('sku', 'BARREL-200-EMPTY')->first();
        $foamWarehouse = Warehouse::where('operating_unit_id', $foamUnit->id)->first();

        // Chemicals + opening lots
        $chemicals = [
            ['name' => 'Polyol 15%', 'sku' => 'CHEM-POLYOL-15', 'capacity' => 200, 'empty' => $emptyBarrel200, 'cost' => 3.10, 'barrels' => 6],
            ['name' => 'Polyol 45%', 'sku' => 'CHEM-POLYOL-45', 'capacity' => 200, 'empty' => $emptyBarrel200, 'cost' => 3.45, 'barrels' => 6],
            ['name' => 'TDI (Sabec)', 'sku' => 'CHEM-TDI-SABEC', 'capacity' => 200, 'empty' => $emptyBarrel200, 'cost' => 5.20, 'barrels' => 8],
            ['name' => 'Amine A33', 'sku' => 'CHEM-AMINE-A33', 'capacity' => 40, 'empty' => $emptyBarrel40, 'cost' => 12.00, 'barrels' => 2],
            ['name' => 'Catalyst T-9', 'sku' => 'CHEM-T9', 'capacity' => 40, 'empty' => $emptyBarrel40, 'cost' => 18.50, 'barrels' => 2],
            ['name' => 'Silicone JC-7858', 'sku' => 'CHEM-SIL-JC7858', 'capacity' => 40, 'empty' => $emptyBarrel40, 'cost' => 9.75, 'barrels' => 3],
            ['name' => 'Methylene Chloride', 'sku' => 'CHEM-MC', 'capacity' => 200, 'empty' => $emptyBarrel200, 'cost' => 2.40, 'barrels' => 3],
        ];

        $attributeModels = [
            'purity_pct' => InventoryAttributeDefinition::where('slug', 'purity_pct')->first(),
            'viscosity_cps' => InventoryAttributeDefinition::where('slug', 'viscosity_cps')->first(),
        ];

        foreach ($chemicals as $index => $chemical) {
            $item = InventoryItem::firstOrCreate(['sku' => $chemical['sku']], [
                'category_id' => $chemicalCategoryId,
                'name' => $chemical['name'],
                'sku' => $chemical['sku'],
                'item_type' => 'raw_material',
                'unit_of_measure' => 'liter',
                'primary_uom' => 'barrel',
                'secondary_uom' => 'liter',
                'container_capacity' => $chemical['capacity'],
                'empty_container_item_id' => $chemical['empty']->id,
            ]);

            if ($attributeModels['purity_pct'] !== null && $attributeModels['viscosity_cps'] !== null) {
                $item->attributeDefinitions()->syncWithoutDetaching([
                    $attributeModels['purity_pct']->id,
                    $attributeModels['viscosity_cps']->id,
                ]);
            }

            StockLot::firstOrCreate(
                ['lot_number' => sprintf('LOT-%s-%03d', $chemical['sku'], $index + 1)],
                [
                    'id' => (string) Str::uuid(),
                    'inventory_item_id' => $item->id,
                    'warehouse_id' => $foamWarehouse->id,
                    'quantity' => $chemical['barrels'] * $chemical['capacity'],
                    'container_quantity' => $chemical['barrels'],
                    'unit_cost' => $chemical['cost'],
                    'status' => 'available',
                    'attribute_values' => ['purity_pct' => 99.2],
                ],
            );
        }

        // Foam outputs
        $foamCategoryId = ItemCategory::where('code', 'CAT-FOAM')->value('id');
        $foamBlock = InventoryItem::firstOrCreate(['sku' => 'BLOCK-WHITE-1214'], [
            'category_id' => $foamCategoryId,
            'name' => 'Foam Block — White 12-14',
            'sku' => 'BLOCK-WHITE-1214',
            'item_type' => 'foam_block',
            'unit_of_measure' => 'm3',
        ]);
        $attributeDensity = InventoryAttributeDefinition::where('slug', 'density_kg_m3')->first();
        $attributeColour = InventoryAttributeDefinition::where('slug', 'colour')->first();
        if ($attributeDensity !== null && $attributeColour !== null) {
            $foamBlock->attributeDefinitions()->syncWithoutDetaching([$attributeDensity->id, $attributeColour->id]);
        }

        InventoryItem::firstOrCreate(['sku' => 'SCRAP-FILL'], [
            'category_id' => $foamCategoryId,
            'name' => 'Foam Scrap Fill',
            'sku' => 'SCRAP-FILL',
            'item_type' => 'byproduct_fill',
            'unit_of_measure' => 'm3',
        ]);

        InventoryItem::firstOrCreate(['sku' => 'SLICE-STD'], [
            'category_id' => $foamCategoryId,
            'name' => 'Foam Slice',
            'sku' => 'SLICE-STD',
            'item_type' => 'slice',
            'unit_of_measure' => 'each',
        ]);

        // Tank stocks for primary chemicals
        foreach (['CHEM-POLYOL-15', 'CHEM-POLYOL-45', 'CHEM-TDI-SABEC', 'CHEM-MC'] as $sku) {
            $item = InventoryItem::where('sku', $sku)->first();
            if ($item === null) {
                continue;
            }
            $lotCost = (float) StockLot::where('inventory_item_id', $item->id)->value('unit_cost');
            TankStock::firstOrCreate(
                ['chemical_inventory_item_id' => $item->id, 'operating_unit_id' => $foamUnit->id],
                [
                    'id' => (string) Str::uuid(),
                    'quantity_on_hand' => 2500,
                    'weighted_avg_unit_cost' => $lotCost,
                ],
            );
        }

        // A pending adjustment so the approval queue is not empty
        $requester = User::where('email', 'foam@erp.com')->first();
        $polyolLot = StockLot::whereHas('inventoryItem', fn ($q) => $q->where('sku', 'CHEM-POLYOL-15'))->first();
        if ($requester !== null && $polyolLot !== null && StockAdjustmentRequest::where('stock_lot_id', $polyolLot->id)->doesntExist()) {
            StockAdjustmentRequest::create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $foamUnit->id,
                'stock_lot_id' => $polyolLot->id,
                'reason_code' => 'spill_loss',
                'quantity_delta' => -12.5,
                'notes' => 'Spill during transfer to tank.',
                'status' => 'pending',
                'requested_by_user_id' => $requester->id,
            ]);
        }

        // Opening inventory journal so GL agrees with stock ledger.
        if (Account::where('account_code', '111')->exists() && ! $this->openingInventoryAlreadyPosted($foamUnit)) {
            $lotValue = (float) StockLot::query()->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as v')->value('v');
            $tankValue = (float) TankStock::query()->selectRaw('COALESCE(SUM(quantity_on_hand * weighted_avg_unit_cost), 0) as v')->value('v');
            $openingValue = round($lotValue + $tankValue, 4);

            if ($openingValue > 0) {
                app(AccountingService::class)->postJournal(
                    'Opening inventory balances (bootstrap)',
                    [
                        ['account_code' => '111', 'debit' => $openingValue, 'operating_unit_id' => $foamUnit->id, 'memo' => 'seeded chemical lots + tank charges'],
                        ['account_code' => '31', 'credit' => $openingValue, 'operating_unit_id' => $foamUnit->id],
                    ],
                );
            }
        }
    }

    private function openingInventoryAlreadyPosted(OperatingUnit $unit): bool
    {
        return JournalEntry::query()
            ->where('description', 'Opening inventory balances (bootstrap)')
            ->whereHas('lines', fn ($q) => $q->where('operating_unit_id', $unit->id))
            ->exists();
    }

    private function seedEntityBackedClient(OperatingUnit $unit): void
    {
        $entity = Entity::firstOrCreate(
            ['name' => 'Sahara Trading & Contracting Co.'],
            [
                'id' => (string) Str::uuid(),
                'entity_type' => EntityType::Organization,
                'tax_number' => 'TAX-LIB-900800',
                'is_active' => true,
            ],
        );
        EntityContact::firstOrCreate(
            ['entity_id' => $entity->id, 'is_primary' => true],
            [
                'email' => 'info@saharatrading.ly',
                'phone' => '+218-91-100-2000',
                'city' => 'Tripoli',
                'address' => 'Gargaresch Road',
            ],
        );
        EntityRole::firstOrCreate(
            ['entity_id' => $entity->id, 'role_type' => EntityRoleType::Client],
            ['operating_unit_id' => $unit->id],
        );

        if (! $entity->client()->exists()) {
            $entity->client()->create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $unit->id,
                'credit_limit' => 250_000,
                'payment_terms_days' => 45,
                'status' => ClientStatus::Active,
            ]);
        }
    }

    private function seedEntityBackedEmployee(OperatingUnit $unit): void
    {
        $entity = Entity::firstOrCreate(
            ['name' => 'Nasser Al-Deen Ahmed'],
            [
                'id' => (string) Str::uuid(),
                'entity_type' => EntityType::Individual,
                'tax_number' => 'NAT-88776655',
                'is_active' => true,
            ],
        );
        EntityRole::firstOrCreate(
            ['entity_id' => $entity->id, 'role_type' => EntityRoleType::Employee],
            ['operating_unit_id' => $unit->id],
        );

        if (! $entity->employee()->exists()) {
            $entity->employee()->create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $unit->id,
                'job_title' => 'Senior Foam Plant Engineer',
                'pay_type' => PayType::Monthly,
                'monthly_salary' => 3500,
                'hire_date' => now()->subYears(2)->toDateString(),
                'status' => EmployeeStatus::Active,
            ]);
        }
    }

    private function seedSuppliersAndImportOrders(OperatingUnit $unit): void
    {
        $supplier = Supplier::firstOrCreate(
            ['name' => 'Global Chemical & Polymer Corp'],
            [
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $unit->id,
                'contact' => 'orders@globalchem.com',
                'default_currency' => 'USD',
                'address' => 'Istanbul Industrial Park, Turkey',
            ],
        );

        Supplier::firstOrCreate(
            ['name' => 'Cairo Foam & Filling Co.'],
            [
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $unit->id,
                'contact' => 'sales@cairofoam.eg',
                'default_currency' => 'EGP',
                'address' => 'Cairo Industrial Zone, Egypt',
            ],
        );

        // Order 1: in transit with bank hold
        $order1 = ImportOrder::where('supplier_id', $supplier->id)
            ->where('negotiated_price', 125.00)
            ->where('quantity', 1000)
            ->where('status', ImportOrderStatus::InTransit->value)
            ->first();

        if ($order1 === null) {
            $order1 = ImportOrder::create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $unit->id,
                'supplier_id' => $supplier->id,
                'currency' => 'USD',
                'negotiated_price' => 125.00,
                'quantity' => 1000,
                'status' => ImportOrderStatus::InTransit,
            ]);
        }

        if (! PaymentRequest::where('invoice_ref', 'INV-CHEM-2026-001')->exists()) {
            $paymentReq = PaymentRequest::create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $unit->id,
                'import_order_id' => $order1->id,
                'route' => PaymentRoute::Bank,
                'invoice_ref' => 'INV-CHEM-2026-001',
                'amount_requested' => 125_000,
                'status' => PaymentRequestStatus::Paid,
                'fx_rate_used' => 5.20,
            ]);

            BankHold::create([
                'id' => (string) Str::uuid(),
                'payment_request_id' => $paymentReq->id,
                'held_amount_lyd' => 675_000,
                'exact_amount_used' => 650_000,
                'released_amount' => 25_000,
                'bank_reference' => 'BNK-REF-TRIPOLI-901',
            ]);

            if (Account::where('account_code', '15')->exists() && ! $this->paymentAlreadyPosted($paymentReq->id)) {
                app(AccountingService::class)->postJournal(
                    "Import payment executed — {$supplier->name} (bootstrap)",
                    [
                        ['account_code' => '15', 'debit' => 650_000, 'operating_unit_id' => $unit->id, 'memo' => $supplier->name],
                        ['account_code' => '12', 'credit' => 650_000, 'operating_unit_id' => $unit->id],
                    ],
                    'PaymentRequest',
                    $paymentReq->id,
                );
            }
        }

        if (LandedCostLine::where('import_order_id', $order1->id)->doesntExist()) {
            LandedCostLine::create([
                'id' => (string) Str::uuid(),
                'import_order_id' => $order1->id,
                'type' => LandedCostType::Freight,
                'amount' => 18_500,
                'currency' => 'LYD',
                'is_confirmed' => true,
            ]);
            LandedCostLine::create([
                'id' => (string) Str::uuid(),
                'import_order_id' => $order1->id,
                'type' => LandedCostType::Customs,
                'amount' => 12_000,
                'currency' => 'LYD',
                'is_confirmed' => true,
            ]);
        }

        // Order 2: received at warehouse
        $order2 = ImportOrder::where('supplier_id', $supplier->id)
            ->where('negotiated_price', 85.00)
            ->where('quantity', 500)
            ->where('status', ImportOrderStatus::Received->value)
            ->first();

        if ($order2 === null) {
            $order2 = ImportOrder::create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $unit->id,
                'supplier_id' => $supplier->id,
                'currency' => 'USD',
                'negotiated_price' => 85.00,
                'quantity' => 500,
                'booked_fx_rate' => 5.05,
                'status' => ImportOrderStatus::Received,
            ]);
        }

        if (! PaymentRequest::where('invoice_ref', 'INV-CHEM-2026-002')->exists()) {
            $paymentReq2 = PaymentRequest::create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $unit->id,
                'import_order_id' => $order2->id,
                'route' => PaymentRoute::Market,
                'invoice_ref' => 'INV-CHEM-2026-002',
                'amount_requested' => 42_500,
                'status' => PaymentRequestStatus::Paid,
                'fx_rate_used' => 5.15,
            ]);

            if (Account::where('account_code', '15')->exists() && ! $this->paymentAlreadyPosted($paymentReq2->id)) {
                app(AccountingService::class)->postJournal(
                    "Import payment executed — {$supplier->name} (bootstrap)",
                    [
                        ['account_code' => '15', 'debit' => 218_875, 'operating_unit_id' => $unit->id, 'memo' => $supplier->name],
                        ['account_code' => '12', 'credit' => 218_875, 'operating_unit_id' => $unit->id],
                    ],
                    'PaymentRequest',
                    $paymentReq2->id,
                );
            }
        }

        $warehouse = Warehouse::where('operating_unit_id', $unit->id)->first() ?? Warehouse::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'name' => 'Central Import Depot',
            'is_internal_unit' => true,
        ]);

        if (! GoodsReceipt::where('import_order_id', $order2->id)->exists()) {
            GoodsReceipt::create([
                'id' => (string) Str::uuid(),
                'import_order_id' => $order2->id,
                'warehouse_id' => $warehouse->id,
                'received_qty' => 500,
                'condition_notes' => 'Received 500 chemical drums sealed in top grade quality.',
            ]);
        }
    }

    private function paymentAlreadyPosted(string $paymentRequestId): bool
    {
        return JournalEntry::query()
            ->where('source_type', 'PaymentRequest')
            ->where('source_id', $paymentRequestId)
            ->exists();
    }

    /**
     * @param  array<string, OperatingUnit>  $units
     */
    private function seedStandardUsers(array $units): void
    {
        $ownerRole = Role::where('slug', 'owner')->first();

        $standardUsers = [
            ['name' => 'Nick Owner', 'email' => 'owner@erp.com', 'role_slug' => 'owner', 'unit_id' => null],
            ['name' => 'حازم', 'email' => 'hazemfast@gmail.com', 'role_slug' => 'owner', 'unit_id' => null],
            ['name' => 'Accounting Manager', 'email' => 'accounting@erp.com', 'role_slug' => 'accounting-manager', 'unit_id' => null],
            ['name' => 'HR Manager', 'email' => 'hr@erp.com', 'role_slug' => 'hr-manager', 'unit_id' => null],
            ['name' => 'Procurement Manager', 'email' => 'procurement@erp.com', 'role_slug' => 'procurement-manager', 'unit_id' => $units['procurement']->id],
            ['name' => 'Treasury Officer', 'email' => 'treasury@erp.com', 'role_slug' => 'treasury-officer', 'unit_id' => $units['procurement']->id],
            ['name' => 'Foam Plant Manager', 'email' => 'foam@erp.com', 'role_slug' => 'foam-manager', 'unit_id' => $units['foam']->id],
            ['name' => 'Foam Operator', 'email' => 'foam-op@erp.com', 'role_slug' => 'foam-operator', 'unit_id' => $units['foam']->id],
            ['name' => 'Cutter Manager', 'email' => 'cutter@erp.com', 'role_slug' => 'cutter-manager', 'unit_id' => $units['cutter']->id],
            ['name' => 'Cutter Operator', 'email' => 'cutter-op@erp.com', 'role_slug' => 'cutter-operator', 'unit_id' => $units['cutter']->id],
            ['name' => 'Furniture Manager', 'email' => 'furniture@erp.com', 'role_slug' => 'furniture-manager', 'unit_id' => $units['furniture']->id],
            ['name' => 'Furniture Assembler', 'email' => 'assembler@erp.com', 'role_slug' => 'assembler', 'unit_id' => $units['furniture']->id],
            ['name' => 'Showroom Manager', 'email' => 'showroom@erp.com', 'role_slug' => 'store-manager', 'unit_id' => $units['showroom']->id],
            ['name' => 'POS Cashier', 'email' => 'cashier@erp.com', 'role_slug' => 'pos-cashier', 'unit_id' => $units['showroom']->id],
        ];

        foreach ($standardUsers as $row) {
            $user = User::withTrashed()->firstOrNew(['email' => $row['email']]);

            if ($user->trashed()) {
                $user->restore();
            }

            if (! $user->exists) {
                $user->id = (string) Str::uuid();
            }

            $user->name = $row['name'];
            $user->password = 'password';
            $user->is_active = true;
            $user->must_change_password = false;
            $user->save();

            $role = $row['role_slug'] === 'owner' ? $ownerRole : Role::where('slug', $row['role_slug'])->first();
            if ($role === null) {
                continue;
            }

            UserRole::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'operating_unit_id' => $row['unit_id'],
                ],
                ['id' => (string) Str::uuid()],
            );
        }
    }

    private function seedOneFoamBatch(OperatingUnit $foamUnit): void
    {
        if (ProductionBatch::query()->where('operating_unit_id', $foamUnit->id)->exists()) {
            return;
        }

        $batchService = app(ProductionBatchService::class);
        $consumptionService = app(ConsumptionReportService::class);

        $warehouse = Warehouse::where('operating_unit_id', $foamUnit->id)->first();
        $blockItem = InventoryItem::where('sku', 'BLOCK-WHITE-1214')->first();
        $scrapItem = InventoryItem::where('sku', 'SCRAP-FILL')->first();

        if ($warehouse === null || $blockItem === null || $scrapItem === null) {
            return;
        }

        if (! Account::where('account_code', '1121')->exists() || ! Account::where('account_code', '1131')->exists()) {
            return;
        }

        $batch = ProductionBatch::create([
            'operating_unit_id' => $foamUnit->id,
            'operation_number' => 191,
            'bun_width_m' => 2.4,
            'formula_params' => [
                'density_band' => '12-14',
                'cure_time_minutes' => 15,
                'conveyor_speed' => 5,
                'chemical_formula' => 'standard-white',
            ],
            'status' => ProductionBatchStatus::Planned->value,
        ]);

        $batchService->transition($batch, ProductionBatchStatus::Configured);
        $batchService->transition($batch->fresh(), ProductionBatchStatus::Running);

        $consumptionService->record($batch->fresh(), [
            ['chemical_inventory_item_id' => InventoryItem::where('sku', 'CHEM-POLYOL-15')->value('id'), 'quantity_consumed' => 899],
            ['chemical_inventory_item_id' => InventoryItem::where('sku', 'CHEM-POLYOL-45')->value('id'), 'quantity_consumed' => 899],
            ['chemical_inventory_item_id' => InventoryItem::where('sku', 'CHEM-TDI-SABEC')->value('id'), 'quantity_consumed' => 1129],
            ['chemical_inventory_item_id' => InventoryItem::where('sku', 'CHEM-MC')->value('id'), 'quantity_consumed' => 270],
        ]);

        foreach ([ProductionBatchStatus::Consumed, ProductionBatchStatus::Curing, ProductionBatchStatus::ReadyForGrading] as $status) {
            $batchService->transition($batch->fresh(), $status);
        }

        $block = fn (int $count, float $length, float $height) => [
            'kind' => 'block',
            'count' => $count,
            'length_m' => $length,
            'height_m' => $height,
            'pressure' => 35,
            'inventory_item_id' => $blockItem->id,
            'warehouse_id' => $warehouse->id,
            'grade' => 'standard',
            'color' => 'white',
        ];

        $scrap = fn (float $length, float $height) => [
            'kind' => 'scrap',
            'count' => 1,
            'length_m' => $length,
            'height_m' => $height,
            'inventory_item_id' => $scrapItem->id,
            'warehouse_id' => $warehouse->id,
        ];

        $batchService->registerBlocks($batch->fresh(), [
            $block(25, 2.0, 0.8),
            $block(3, 1.99, 1.0),
            $block(2, 1.38, 0.44),
            $block(1, 2.3, 0.78),
            $block(1, 2.5, 0.59),
            $block(1, 1.95, 0.69),
            $block(1, 2.3, 0.75),
            $scrap(2.0, 1.0),
            $scrap(2.0, 0.5),
        ]);

        $batchService->transition($batch->fresh(), ProductionBatchStatus::Graded);
        $batchService->transition($batch->fresh(), ProductionBatchStatus::Closed);
    }
}
