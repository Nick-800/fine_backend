<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentRequestStatus;
use App\Enums\PurchaseOrderKind;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Client;
use App\Models\PayableSettlement;
use App\Models\PaymentRequest;
use App\Models\PurchaseOrder;
use App\Models\SalePayment;
use App\Models\SalesOrder;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PartnerReportService
{
    /**
     * Cross-client balances and aging summary.
     *
     * @return array<string, mixed>
     */
    public function clientBalances(?string $asOf = null, ?string $operatingUnitId = null): array
    {
        $asOf ??= now()->toDateString();
        $asOfEnd = Carbon::parse($asOf)->endOfDay();

        $query = Client::query()->with(['entity', 'operatingUnit']);
        if ($operatingUnitId !== null) {
            $query->where('operating_unit_id', $operatingUnitId);
        }

        $clients = $query->get();

        $rows = [];
        $totalReceivables = 0.0;
        $totalCreditsOrAdvances = 0.0;
        $overdueTotal = 0.0;

        foreach ($clients as $client) {
            $salesOrders = SalesOrder::query()
                ->where('client_id', $client->id)
                ->where('payment_method', 'receivable')
                ->where('status', '!=', SalesOrderStatus::Rejected)
                ->where('created_at', '<=', $asOfEnd)
                ->get();

            $payments = SalePayment::query()
                ->where('client_id', $client->id)
                ->where('created_at', '<=', $asOfEnd)
                ->get();

            $totalDebit = round((float) $salesOrders->sum('total_amount'), 4);
            $totalCredit = round((float) $payments->sum('amount'), 4);
            $netBalance = round($totalDebit - $totalCredit, 4);

            $aging = [
                'current' => 0.0,
                'days_31_60' => 0.0,
                'days_61_90' => 0.0,
                'over_90' => 0.0,
            ];

            // FIFO allocation of payments across chronological credit orders to calculate remaining aging
            $remainingCredit = $totalCredit;
            $sortedOrders = $salesOrders->sortBy('created_at');

            foreach ($sortedOrders as $order) {
                $orderTotal = (float) $order->total_amount;
                if ($remainingCredit >= $orderTotal) {
                    $remainingCredit -= $orderTotal;
                    continue;
                }

                $unpaid = $orderTotal - $remainingCredit;
                $remainingCredit = 0.0;

                $orderDate = Carbon::parse($order->created_at);
                $days = $orderDate->diffInDays(Carbon::parse($asOf), false);

                if ($days <= 30) {
                    $aging['current'] += $unpaid;
                } elseif ($days <= 60) {
                    $aging['days_31_60'] += $unpaid;
                } elseif ($days <= 90) {
                    $aging['days_61_90'] += $unpaid;
                } else {
                    $aging['over_90'] += $unpaid;
                }
            }

            foreach ($aging as $key => $val) {
                $aging[$key] = round($val, 4);
            }

            $clientOverdue = round($aging['days_31_60'] + $aging['days_61_90'] + $aging['over_90'], 4);

            if ($netBalance > 0.0001) {
                $position = 'debit';
                $totalReceivables += $netBalance;
                $overdueTotal += $clientOverdue;
            } elseif ($netBalance < -0.0001) {
                $position = 'credit';
                $totalCreditsOrAdvances += abs($netBalance);
            } else {
                $position = 'settled';
            }

            $rows[] = [
                'id' => $client->id,
                'name' => $client->entity?->name ?? 'عميل غير مسمى',
                'phone' => $client->entity?->phone ?? $client->entity?->primary_contact?->phone ?? '',
                'city' => $client->entity?->city ?? '',
                'operating_unit_id' => $client->operating_unit_id,
                'operating_unit_name' => $client->operatingUnit?->name ?? '—',
                'credit_limit' => round((float) ($client->credit_limit ?? 0), 4),
                'payment_terms_days' => (int) ($client->payment_terms_days ?? 30),
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'net_balance' => $netBalance,
                'balance_type' => $position,
                'aging' => $aging,
                'overdue_amount' => $clientOverdue,
                'status' => $client->status?->value ?? 'active',
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['net_balance'] <=> $a['net_balance']);

        return [
            'type' => 'clients',
            'as_of' => $asOf,
            'operating_unit_id' => $operatingUnitId,
            'summary' => [
                'total_receivables' => round($totalReceivables, 4),
                'total_credits_or_advances' => round($totalCreditsOrAdvances, 4),
                'net_exposure' => round($totalReceivables - $totalCreditsOrAdvances, 4),
                'overdue_total' => round($overdueTotal, 4),
                'total_count' => count($rows),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Cross-supplier balances and aging summary.
     *
     * @return array<string, mixed>
     */
    public function supplierBalances(?string $asOf = null, ?string $operatingUnitId = null): array
    {
        $asOf ??= now()->toDateString();
        $asOfEnd = Carbon::parse($asOf)->endOfDay();

        $query = Supplier::query()->with(['operatingUnit', 'account']);
        if ($operatingUnitId !== null) {
            $query->where('operating_unit_id', $operatingUnitId);
        }

        $suppliers = $query->get();

        $rows = [];
        $totalPayables = 0.0;
        $totalAdvances = 0.0;
        $overdueTotal = 0.0;

        foreach ($suppliers as $supplier) {
            $pos = PurchaseOrder::query()
                ->where('supplier_id', $supplier->id)
                ->where('status', '!=', PurchaseOrderStatus::Draft)
                ->where('created_at', '<=', $asOfEnd)
                ->with(['items', 'paymentRequests'])
                ->get();

            $totalPurchases = 0.0;
            $totalPayments = 0.0;

            foreach ($pos as $po) {
                $cost = (float) $po->totalCost();
                $totalPurchases += $cost;

                if ($po->kind === PurchaseOrderKind::Local) {
                    if (in_array($po->status, [PurchaseOrderStatus::Paid, PurchaseOrderStatus::Closed], true)) {
                        $totalPayments += $cost;
                    }
                } else {
                    // Foreign PO: sum paid payment requests
                    $paidReqs = $po->paymentRequests->where('status', PaymentRequestStatus::Paid);
                    $totalPayments += (float) $paidReqs->sum('amount_requested');
                }
            }

            // Also check for settlements on supplier's sub-account if mapped
            if ($supplier->account?->account_code) {
                $settlements = PayableSettlement::query()
                    ->where('account_code', $supplier->account->account_code)
                    ->where('settled_at', '<=', $asOf)
                    ->sum('amount');
                $totalPayments += (float) $settlements;
            }

            $totalPurchases = round($totalPurchases, 4);
            $totalPayments = round($totalPayments, 4);
            $netBalance = round($totalPurchases - $totalPayments, 4);

            $aging = [
                'current' => 0.0,
                'days_31_60' => 0.0,
                'days_61_90' => 0.0,
                'over_90' => 0.0,
            ];

            $remainingPayments = $totalPayments;
            $sortedPos = $pos->sortBy('created_at');

            foreach ($sortedPos as $po) {
                $poCost = (float) $po->totalCost();
                if ($remainingPayments >= $poCost) {
                    $remainingPayments -= $poCost;
                    continue;
                }

                $unpaid = $poCost - $remainingPayments;
                $remainingPayments = 0.0;

                $poDate = Carbon::parse($po->created_at);
                $days = $poDate->diffInDays(Carbon::parse($asOf), false);

                if ($days <= 30) {
                    $aging['current'] += $unpaid;
                } elseif ($days <= 60) {
                    $aging['days_31_60'] += $unpaid;
                } elseif ($days <= 90) {
                    $aging['days_61_90'] += $unpaid;
                } else {
                    $aging['over_90'] += $unpaid;
                }
            }

            foreach ($aging as $key => $val) {
                $aging[$key] = round($val, 4);
            }

            $supplierOverdue = round($aging['days_31_60'] + $aging['days_61_90'] + $aging['over_90'], 4);

            if ($netBalance > 0.0001) {
                $position = 'credit'; // Fine owes Supplier (payable)
                $totalPayables += $netBalance;
                $overdueTotal += $supplierOverdue;
            } elseif ($netBalance < -0.0001) {
                $position = 'debit'; // Supplier owes Fine (advance)
                $totalAdvances += abs($netBalance);
            } else {
                $position = 'settled';
            }

            $rows[] = [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact' => $supplier->contact ?? '',
                'address' => $supplier->address ?? '',
                'operating_unit_id' => $supplier->operating_unit_id,
                'operating_unit_name' => $supplier->operatingUnit?->name ?? '—',
                'default_currency' => $supplier->default_currency ?? 'LYD',
                'total_purchases' => $totalPurchases,
                'total_payments' => $totalPayments,
                'net_balance' => $netBalance,
                'balance_type' => $position,
                'aging' => $aging,
                'overdue_amount' => $supplierOverdue,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['net_balance'] <=> $a['net_balance']);

        return [
            'type' => 'suppliers',
            'as_of' => $asOf,
            'operating_unit_id' => $operatingUnitId,
            'summary' => [
                'total_payables' => round($totalPayables, 4),
                'total_advances' => round($totalAdvances, 4),
                'net_exposure' => round($totalPayables - $totalAdvances, 4),
                'overdue_total' => round($overdueTotal, 4),
                'total_count' => count($rows),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Itemized Statement of Account for a single Client.
     *
     * @return array<string, mixed>
     */
    public function clientStatement(string $clientId, ?string $from = null, ?string $to = null, ?string $operatingUnitId = null): array
    {
        $clientQuery = Client::query()->with(['entity', 'operatingUnit'])->whereKey($clientId);
        if ($operatingUnitId !== null) {
            $clientQuery->where('operating_unit_id', $operatingUnitId);
        }

        $client = $clientQuery->firstOrFail();

        $fromCarbon = $from !== null ? Carbon::parse($from)->startOfDay() : null;
        $toCarbon = $to !== null ? Carbon::parse($to)->endOfDay() : null;

        // Opening balance calculation: all credit sales minus payments strictly before $from
        $openingDebit = 0.0;
        $openingCredit = 0.0;

        if ($fromCarbon !== null) {
            $openingDebit = (float) SalesOrder::query()
                ->where('client_id', $client->id)
                ->where('payment_method', 'receivable')
                ->where('status', '!=', SalesOrderStatus::Rejected)
                ->where('created_at', '<', $fromCarbon)
                ->sum('total_amount');

            $openingCredit = (float) SalePayment::query()
                ->where('client_id', $client->id)
                ->where('created_at', '<', $fromCarbon)
                ->sum('amount');
        }

        $openingBalance = round($openingDebit - $openingCredit, 4);

        // Period transactions
        $salesQuery = SalesOrder::query()
            ->where('client_id', $client->id)
            ->where('payment_method', 'receivable')
            ->where('status', '!=', SalesOrderStatus::Rejected);

        $paymentsQuery = SalePayment::query()
            ->where('client_id', $client->id)
            ->with(['salesOrder']);

        if ($fromCarbon !== null) {
            $salesQuery->where('created_at', '>=', $fromCarbon);
            $paymentsQuery->where('created_at', '>=', $fromCarbon);
        }

        if ($toCarbon !== null) {
            $salesQuery->where('created_at', '<=', $toCarbon);
            $paymentsQuery->where('created_at', '<=', $toCarbon);
        }

        $transactions = [];

        foreach ($salesQuery->get() as $order) {
            $transactions[] = [
                'id' => $order->id,
                'date' => Carbon::parse($order->created_at)->toDateTimeString(),
                'timestamp' => Carbon::parse($order->created_at)->timestamp,
                'document_type' => 'sale_order',
                'document_type_label' => 'طلب مبيعات آجل',
                'document_number' => $order->order_number,
                'memo' => $order->notes ?? 'مبيعات آجلة',
                'debit' => round((float) $order->total_amount, 4),
                'credit' => 0.0,
            ];
        }

        foreach ($paymentsQuery->get() as $payment) {
            $docNum = $payment->salesOrder?->order_number ? "سداد لطلب {$payment->salesOrder->order_number}" : 'سداد دفعة';
            $methodStr = is_string($payment->method) ? $payment->method : ($payment->method?->value ?? 'cash');
            $transactions[] = [
                'id' => $payment->id,
                'date' => Carbon::parse($payment->created_at)->toDateTimeString(),
                'timestamp' => Carbon::parse($payment->created_at)->timestamp,
                'document_type' => 'sale_payment',
                'document_type_label' => 'سداد دفعة',
                'document_number' => $payment->salesOrder?->order_number ?? 'سداد نقدي',
                'memo' => "طريقة الدفع: {$methodStr} — {$docNum}",
                'debit' => 0.0,
                'credit' => round((float) $payment->amount, 4),
            ];
        }

        // Sort chronologically
        usort($transactions, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

        $running = $openingBalance;
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($transactions as &$tx) {
            $totalDebit += $tx['debit'];
            $totalCredit += $tx['credit'];
            $running = round($running + $tx['debit'] - $tx['credit'], 4);
            $tx['running_balance'] = $running;
            unset($tx['timestamp']);
        }
        unset($tx);

        return [
            'party' => [
                'id' => $client->id,
                'name' => $client->entity?->name ?? 'عميل غير مسمى',
                'phone' => $client->entity?->phone ?? '',
                'address' => $client->entity?->address ?? '',
                'type' => 'client',
                'currency' => 'LYD',
                'operating_unit_id' => $client->operating_unit_id,
                'operating_unit_name' => $client->operatingUnit?->name ?? '—',
                'credit_limit' => round((float) ($client->credit_limit ?? 0), 4),
            ],
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'opening_balance' => $openingBalance,
            'closing_balance' => $running,
            'total_debit' => round($totalDebit, 4),
            'total_credit' => round($totalCredit, 4),
            'net_change' => round($totalDebit - $totalCredit, 4),
            'transactions' => $transactions,
        ];
    }

    /**
     * Itemized Statement of Account for a single Supplier.
     *
     * @return array<string, mixed>
     */
    public function supplierStatement(string $supplierId, ?string $from = null, ?string $to = null, ?string $operatingUnitId = null): array
    {
        $supplierQuery = Supplier::query()->with(['operatingUnit', 'account'])->whereKey($supplierId);
        if ($operatingUnitId !== null) {
            $supplierQuery->where('operating_unit_id', $operatingUnitId);
        }

        $supplier = $supplierQuery->firstOrFail();

        $fromCarbon = $from !== null ? Carbon::parse($from)->startOfDay() : null;
        $toCarbon = $to !== null ? Carbon::parse($to)->endOfDay() : null;

        // Opening balance calculation
        $openingPurchases = 0.0;
        $openingPayments = 0.0;

        if ($fromCarbon !== null) {
            $prePos = PurchaseOrder::query()
                ->where('supplier_id', $supplier->id)
                ->where('status', '!=', PurchaseOrderStatus::Draft)
                ->where('created_at', '<', $fromCarbon)
                ->with(['items', 'paymentRequests'])
                ->get();

            foreach ($prePos as $po) {
                $cost = (float) $po->totalCost();
                $openingPurchases += $cost;

                if ($po->kind === PurchaseOrderKind::Local) {
                    if (in_array($po->status, [PurchaseOrderStatus::Paid, PurchaseOrderStatus::Closed], true)) {
                        $openingPayments += $cost;
                    }
                } else {
                    $paidReqs = $po->paymentRequests->where('status', PaymentRequestStatus::Paid);
                    $openingPayments += (float) $paidReqs->sum('amount_requested');
                }
            }

            if ($supplier->account?->account_code) {
                $openingPayments += (float) PayableSettlement::query()
                    ->where('account_code', $supplier->account->account_code)
                    ->where('settled_at', '<', $from)
                    ->sum('amount');
            }
        }

        $openingBalance = round($openingPurchases - $openingPayments, 4);

        // Period transactions
        $posQuery = PurchaseOrder::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', PurchaseOrderStatus::Draft)
            ->with(['items', 'paymentRequests']);

        if ($fromCarbon !== null) {
            $posQuery->where('created_at', '>=', $fromCarbon);
        }
        if ($toCarbon !== null) {
            $posQuery->where('created_at', '<=', $toCarbon);
        }

        $transactions = [];

        foreach ($posQuery->get() as $po) {
            $cost = round((float) $po->totalCost(), 4);
            $transactions[] = [
                'id' => $po->id,
                'date' => Carbon::parse($po->created_at)->toDateTimeString(),
                'timestamp' => Carbon::parse($po->created_at)->timestamp,
                'document_type' => 'purchase_order',
                'document_type_label' => $po->kind === PurchaseOrderKind::Local ? 'أمر شراء محلي' : 'أمر شراء خارجي',
                'document_number' => "PO-{$po->id}",
                'memo' => "شراء توريد — الحالة: {$po->status->value}",
                'debit' => 0.0,
                'credit' => $cost, // Credit increases liability to supplier
            ];

            // Local PO payment
            if ($po->kind === PurchaseOrderKind::Local && in_array($po->status, [PurchaseOrderStatus::Paid, PurchaseOrderStatus::Closed], true)) {
                $transactions[] = [
                    'id' => "pay-{$po->id}",
                    'date' => Carbon::parse($po->updated_at)->toDateTimeString(),
                    'timestamp' => Carbon::parse($po->updated_at)->timestamp + 1,
                    'document_type' => 'po_payment',
                    'document_type_label' => 'سداد أمر شراء',
                    'document_number' => "PO-{$po->id}",
                    'memo' => 'سداد نقدي/مصرفي لأمر الشراء المحلي',
                    'debit' => $cost, // Debit decreases liability to supplier
                    'credit' => 0.0,
                ];
            } else {
                // Foreign PO payments
                foreach ($po->paymentRequests as $req) {
                    if ($req->status === PaymentRequestStatus::Paid) {
                        $reqAmount = round((float) $req->amount_requested, 4);
                        $transactions[] = [
                            'id' => $req->id,
                            'date' => Carbon::parse($req->updated_at)->toDateTimeString(),
                            'timestamp' => Carbon::parse($req->updated_at)->timestamp + 1,
                            'document_type' => 'po_payment',
                            'document_type_label' => 'حوالة / سداد مالي للمورد',
                            'document_number' => $req->invoice_ref ?? "REQ-{$req->id}",
                            'memo' => "دفعة للمورد عبر المسار: {$req->route->value}",
                            'debit' => $reqAmount,
                            'credit' => 0.0,
                        ];
                    }
                }
            }
        }

        // Also add settlements
        if ($supplier->account?->account_code) {
            $settleQuery = PayableSettlement::query()
                ->where('account_code', $supplier->account->account_code);
            if ($from !== null) {
                $settleQuery->where('settled_at', '>=', $from);
            }
            if ($to !== null) {
                $settleQuery->where('settled_at', '<=', $to);
            }

            foreach ($settleQuery->get() as $settle) {
                $settleAmount = round((float) $settle->amount, 4);
                $transactions[] = [
                    'id' => $settle->id,
                    'date' => Carbon::parse($settle->settled_at)->toDateTimeString(),
                    'timestamp' => Carbon::parse($settle->settled_at)->timestamp,
                    'document_type' => 'payable_settlement',
                    'document_type_label' => 'تسوية التزامات مورد',
                    'document_number' => $settle->reference ?? "SETTLE-{$settle->id}",
                    'memo' => "تسوية حساب ذمم الدائنين: {$settle->account_code}",
                    'debit' => $settleAmount,
                    'credit' => 0.0,
                ];
            }
        }

        // Sort chronologically
        usort($transactions, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

        $running = $openingBalance;
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($transactions as &$tx) {
            $totalDebit += $tx['debit'];
            $totalCredit += $tx['credit'];
            $running = round($running + $tx['credit'] - $tx['debit'], 4);
            $tx['running_balance'] = $running;
            unset($tx['timestamp']);
        }
        unset($tx);

        return [
            'party' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact' => $supplier->contact ?? '',
                'address' => $supplier->address ?? '',
                'type' => 'supplier',
                'currency' => $supplier->default_currency ?? 'LYD',
                'operating_unit_id' => $supplier->operating_unit_id,
                'operating_unit_name' => $supplier->operatingUnit?->name ?? '—',
            ],
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'opening_balance' => $openingBalance,
            'closing_balance' => $running,
            'total_debit' => round($totalDebit, 4),
            'total_credit' => round($totalCredit, 4),
            'net_change' => round($totalCredit - $totalDebit, 4),
            'transactions' => $transactions,
        ];
    }
}
