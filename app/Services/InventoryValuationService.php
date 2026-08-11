<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StockLot;
use App\Models\TankStock;

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

        $tankValuation = TankStock::where('operating_unit_id', $operatingUnitId)
            ->selectRaw('SUM(quantity_on_hand * weighted_avg_unit_cost) as total_tank_value, COUNT(id) as total_tanks')
            ->first();

        $stockLotValue = round((float) ($lotValuation->total_value ?? 0.0), 4);
        $tankValue = round((float) ($tankValuation->total_tank_value ?? 0.0), 4);

        return [
            'operating_unit_id' => $operatingUnitId,
            'stock_lot_valuation' => $stockLotValue,
            'total_lots' => (int) ($lotValuation->total_lots ?? 0),
            'tank_stock_valuation' => $tankValue,
            'total_tanks' => (int) ($tankValuation->total_tanks ?? 0),
            'total_valuation' => round($stockLotValue + $tankValue, 4),
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

        $totalTankValuation = TankStock::selectRaw('SUM(quantity_on_hand * weighted_avg_unit_cost) as total_value')
            ->value('total_value') ?? 0.0;

        $lotVal = round((float) $totalLotValuation, 4);
        $tankVal = round((float) $totalTankValuation, 4);

        return [
            'total_stock_lots_value' => $lotVal,
            'total_tank_stocks_value' => $tankVal,
            'company_total_valuation' => round($lotVal + $tankVal, 4),
            'currency' => 'LYD',
        ];
    }
}
