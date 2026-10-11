<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Client;
use App\Support\ReceivableAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills journal_lines.client_id on historical receivable lines (account 122
 * and descendants) that predate party tagging.
 *
 * Pass 1 uses the client's dedicated sub-account (clients.account_id), only on
 * leaf accounts of the subtree (never the root 122 or a grouping account) and
 * skipping accounts shared by several clients. Pass 2 uses the source
 * SalesOrder's client for lines still untagged. Idempotent; only touches NULL client_id.
 */
final class BackfillJournalLineClients extends Command
{
    protected $signature = 'ledger:backfill-clients {--dry-run : Report counts without writing}';

    protected $description = 'Backfill client_id on historical receivable journal lines';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $subtree = ReceivableAccounts::subtreeIds();

        $untaggedAtStart = $subtree === [] ? 0 : DB::table('journal_lines')
            ->whereNull('client_id')
            ->whereIn('account_id', $subtree)
            ->count();

        /** @var array<string, true> $assigned ids handled by pass 1, so pass 2 skips them in dry-run too */
        $assigned = [];
        $bySubAccount = $this->bySubAccount($subtree, $dryRun, $assigned);
        $bySalesOrder = $this->bySalesOrder($subtree, $dryRun, $assigned);

        $this->line("By sub-account: {$bySubAccount}");
        $this->line("By sales order: {$bySalesOrder}");
        $this->line('Still unassigned: '.($untaggedAtStart - $bySubAccount - $bySalesOrder));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $subtree
     * @param  array<string, true>  $assigned
     */
    private function bySubAccount(array $subtree, bool $dryRun, array &$assigned): int
    {
        if ($subtree === []) {
            return 0;
        }

        $leafIds = $this->leafAccountIds($subtree);

        if ($leafIds === []) {
            return 0;
        }

        $clientByAccount = Client::query()
            ->whereIn('account_id', $leafIds)
            ->get(['id', 'account_id'])
            ->groupBy('account_id')
            ->filter(fn ($clients) => $clients->count() === 1)
            ->map(fn ($clients) => $clients->first()->id);

        $count = 0;

        foreach ($clientByAccount as $accountId => $clientId) {
            DB::transaction(function () use ($accountId, $clientId, $dryRun, &$assigned, &$count): void {
                DB::table('journal_lines')
                    ->whereNull('client_id')
                    ->where('account_id', $accountId)
                    ->chunkById(500, function ($lines) use ($clientId, $dryRun, &$assigned, &$count): void {
                        $ids = $lines->pluck('id')->all();
                        $count += count($ids);
                        array_walk($ids, function ($id) use (&$assigned): void {
                            $assigned[$id] = true;
                        });

                        if (! $dryRun) {
                            DB::table('journal_lines')->whereIn('id', $ids)->update(['client_id' => $clientId]);
                        }
                    }, 'id');
            });
        }

        return $count;
    }

    /**
     * Leaf accounts of the subtree: never the root 122 or a grouping account,
     * whose lines may belong to many clients even when only one is linked.
     *
     * @param  array<int, string>  $subtree
     * @return array<int, string>
     */
    private function leafAccountIds(array $subtree): array
    {
        $parentIds = Account::query()
            ->whereIn('parent_account_id', $subtree)
            ->distinct()
            ->pluck('parent_account_id')
            ->all();

        return array_values(array_diff(array_slice($subtree, 1), $parentIds));
    }

    /**
     * @param  array<int, string>  $subtree
     * @param  array<string, true>  $assigned
     */
    private function bySalesOrder(array $subtree, bool $dryRun, array $assigned): int
    {
        if ($subtree === []) {
            return 0;
        }

        $count = 0;

        DB::transaction(function () use ($subtree, $dryRun, $assigned, &$count): void {
            DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->join('sales_orders', 'sales_orders.id', '=', 'journal_entries.source_document_id')
                ->whereNull('journal_lines.client_id')
                ->whereIn('journal_lines.account_id', $subtree)
                ->where('journal_entries.source_document_type', 'SalesOrder')
                ->whereNotNull('sales_orders.client_id')
                ->select(['journal_lines.id as id', 'sales_orders.client_id as client_id'])
                ->chunkById(500, function ($rows) use ($dryRun, $assigned, &$count): void {
                    foreach ($rows->groupBy('client_id') as $clientId => $group) {
                        $ids = $group->pluck('id')->reject(fn ($id) => isset($assigned[$id]))->all();
                        $count += count($ids);

                        if (! $dryRun && $ids !== []) {
                            DB::table('journal_lines')->whereIn('id', $ids)->update(['client_id' => $clientId]);
                        }
                    }
                }, 'journal_lines.id', 'id');
        });

        return $count;
    }
}
