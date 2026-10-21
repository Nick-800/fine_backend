<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill currency, original_amount, and fx_rate on historical supplier advance journal lines.
 *
 * ACC-15 / BR-CUR-008:
 * Journal lines are immutable at the model level (JournalLine::booted() throws
 * LEDGER_IMMUTABLE), so updates must be written directly via DB::table('journal_lines')
 * since these are descriptive tracking columns only; debit/credit amounts never change.
 * Landed-cost clearing lines are skipped as ambiguous because their original currency
 * cannot be reliably reconstructed from historical payment request records.
 */
final class BackfillLineCurrency extends Command
{
    protected $signature = 'ledger:backfill-line-currency {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill currency and original amounts on historical supplier advance lines';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $entries = DB::table('journal_entries')
            ->where('source_document_type', 'PaymentRequest')
            ->whereNull('deleted_at')
            ->get(['id', 'source_document_id']);

        $stamped = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $paymentRequest = DB::table('payment_requests')
                ->where('id', $entry->source_document_id)
                ->first(['id', 'purchase_order_id', 'fx_rate_used']);

            $order = $paymentRequest
                ? DB::table('purchase_orders')
                    ->where('id', $paymentRequest->purchase_order_id)
                    ->first(['id', 'currency', 'payment_source_account_id'])
                : null;

            $treasuryAccountId = $order?->payment_source_account_id;

            $candidateLines = DB::table('journal_lines')
                ->where('journal_entry_id', $entry->id)
                ->where('debit', '>', 0)
                ->when($treasuryAccountId !== null, fn ($q) => $q->where('account_id', '!=', $treasuryAccountId))
                ->get(['id', 'debit', 'currency']);

            $isEligibleOrder = $paymentRequest !== null
                && $order !== null
                && $paymentRequest->fx_rate_used !== null
                && (float) $paymentRequest->fx_rate_used > 0
                && strtoupper((string) $order->currency) !== 'LYD';

            foreach ($candidateLines as $line) {
                if ($line->currency !== null) {
                    $skipped++;

                    continue;
                }

                if (! $isEligibleOrder) {
                    $skipped++;

                    continue;
                }

                $fxRateUsed = (float) $paymentRequest->fx_rate_used;
                $originalAmount = round((float) $line->debit / $fxRateUsed, 4);

                if ($dryRun) {
                    $this->line("Would stamp line {$line->id}: {$order->currency} {$originalAmount} @ {$fxRateUsed}");
                } else {
                    DB::table('journal_lines')
                        ->where('id', $line->id)
                        ->update([
                            'currency' => $order->currency,
                            'original_amount' => $originalAmount,
                            'fx_rate' => $fxRateUsed,
                        ]);
                }

                $stamped++;
            }
        }

        $this->line("{$stamped} lines stamped, {$skipped} skipped");

        return self::SUCCESS;
    }
}
