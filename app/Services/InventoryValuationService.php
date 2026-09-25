<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StockLot;

class InventoryValuationService
{
    /**
     * Get inventory valuation summary for a specific operating unit.
     */
    public function getUnitValuation(string $operatingUnitId): array
    {
        $lotValuation = StockLot::whereHas('warehouse', function ($q) use ($operatingUnitId) {
            $q->where('operating_unit_id', $operatingUnitId);
        })
            ->where('status', 'available')
            ->selectRaw('SUM(quantity * unit_cost) as total_value, COUNT(id) as total_lots')
            ->first();

        $stockLotValue = round((float) ($lotValuation->total_value ?? 0.0), 4);

        return [
            'operating_unit_id' => $operatingUnitId,
            'stock_lot_valuation' => $stockLotValue,
            'total_lots' => (int) ($lotValuation->total_lots ?? 0),
            'total_valuation' => $stockLotValue,
            'currency' => 'LYD',
        ];
    }

    /**
     * Get company-wide total inventory valuation rollup.
     */
    public function getCompanyRollup(): array
    {
        $totalLotValuation = StockLot::where('status', 'available')
            ->selectRaw('SUM(quantity * unit_cost) as total_value')
            ->value('total_value') ?? 0.0;

        $lotVal = round((float) $totalLotValuation, 4);

        return [
            'total_stock_lots_value' => $lotVal,
            'company_total_valuation' => $lotVal,
            'currency' => 'LYD',
        ];
    }
}
