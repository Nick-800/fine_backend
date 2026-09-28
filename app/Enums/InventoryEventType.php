<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every accounting event an inventory item can be linked to.
 *
 * Each item in the chart carries one (item × event) override row pointing at a
 * specific `accounts.id`. Posting services consult `InventoryItem::accountFor()`
 * to resolve the override; the absence of one is a hard 422
 * `INVENTORY_ACCOUNT_NOT_LINKED` — no canonical fallback.
 */
enum InventoryEventType: string
{
    case Opening = 'opening';
    case Ending = 'ending';
    case Purchases = 'purchases';
    case Sales = 'sales';
    case PurchaseReturns = 'purchase_returns';
    case SalesReturns = 'sales_returns';
    case Cogs = 'cogs';
    case Waste = 'waste';
    case EarnedDiscount = 'earned_discount';
    case GrantedDiscount = 'granted_discount';
    case TransportIn = 'transport_in';
    case SalesCommission = 'sales_commission';

    public function arabicLabel(): string
    {
        return match ($this) {
            self::Opening => 'بضاعة أول المدة',
            self::Ending => 'بضاعة آخر المدة',
            self::Purchases => 'حساب المشتريات',
            self::Sales => 'حساب المبيعات',
            self::PurchaseReturns => 'حساب مردود المشتريات',
            self::SalesReturns => 'حساب مردودة مبيعات',
            self::Cogs => 'تكلفة البضاعة المباعة',
            self::Waste => 'حساب إهلاك / هالك',
            self::EarnedDiscount => 'حساب خصم مكتسب',
            self::GrantedDiscount => 'حساب خصم ممنوح',
            self::TransportIn => 'حساب غبور المشتريات',
            self::SalesCommission => 'حساب عمولة المبيعات',
        };
    }
}
