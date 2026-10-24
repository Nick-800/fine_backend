<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChequeStatus;
use App\Exceptions\HeaderAccountPostingException;
use App\Exceptions\LedgerRuleException;
use App\Models\Account;
use App\Models\CashAccount;
use App\Models\Cheque;
use App\Models\ChequeEvent;
use App\Models\Client;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OperatingUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AccountTree;
use App\Support\CompanyClock;
use App\Support\LedgerAccounts;
use App\Support\SalesAccounts;
use App\Support\TreasuryAccounts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 08 Wave 2 / Rule TRS-05: Customer and supplier cheques (الشيكات الواردة والصادرة).
 * A cheque moves only along its allowed states, one entry per move.
 */
final class ChequeService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly DocumentNumberService $documentNumbers,
        private readonly TreasuryBalanceService $balanceService,
    ) {}

    /**
     * Receive a customer cheque into the portfolio.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LedgerRuleException
     */
    public function receive(array $data, User $user): Cheque
    {
        return DB::transaction(function () use ($data, $user): Cheque {
            $direction = (string) ($data['direction'] ?? 'incoming');
            if ($direction === 'incoming') {
                $clientId = $data['client_id'] ?? null;
                if ($clientId === null) {
                    throw new LedgerRuleException('A client is required for incoming cheques.', 'CLIENT_REQUIRED');
                }
                $client = Client::whereKey($clientId)->lockForUpdate()->first();
                if ($client === null) {
                    throw new LedgerRuleException('A client is required for incoming cheques.', 'CLIENT_REQUIRED');
                }
            } else {
                throw new LedgerRuleException("Direction {$direction} is not supported in this package.", 'UNSUPPORTED_DIRECTION');
            }

            $currency = strtoupper(trim((string) ($data['currency'] ?? 'LYD')));
            if ($currency !== 'LYD') {
                throw new LedgerRuleException('Foreign currency cheques are not supported in this package.', 'CHEQUE_CURRENCY_UNSUPPORTED');
            }

            $amount = round((float) ($data['amount'] ?? 0), 4);
            if ($amount <= 0) {
                throw new LedgerRuleException('A cheque amount must be greater than zero.', 'INVALID_AMOUNT');
            }

            $dueDate = $data['due_date'] ?? null;
            if (empty($dueDate)) {
                throw new LedgerRuleException('A due date is required for cheques.', 'DUE_DATE_REQUIRED');
            }

            $chequeNo = trim((string) ($data['cheque_no'] ?? ''));
            if ($chequeNo === '') {
                throw new LedgerRuleException('A cheque number is required.', 'CHEQUE_NO_REQUIRED');
            }

            $bankName = trim((string) ($data['bank_name'] ?? ''));
            if ($bankName === '') {
                throw new LedgerRuleException('A bank name is required.', 'BANK_NAME_REQUIRED');
            }

            $receivedDate = isset($data['received_date']) && trim((string) $data['received_date']) !== ''
                ? (string) $data['received_date']
                : CompanyClock::today();

            if (Carbon::parse($receivedDate)->gt(Carbon::parse(CompanyClock::today()))) {
                throw new LedgerRuleException('The event date cannot be in the future.', 'CHEQUE_DATE_IN_FUTURE');
            }

            $companyId = $data['company_id'] ?? Company::query()->value('id');
            if ($companyId === null) {
                throw new LedgerRuleException('No company exists to post a cheque against.', 'NO_COMPANY');
            }

            $operatingUnitId = $data['operating_unit_id']
                ?? $client->operating_unit_id
                ?? OperatingUnit::query()->value('id');

            if ($operatingUnitId === null) {
                throw new LedgerRuleException('Operating unit is required.', 'OPERATING_UNIT_REQUIRED');
            }

            $year = (int) Carbon::parse($receivedDate)->format('Y');
            $chequeNumberInternal = $this->documentNumbers->next(DocumentNumberService::CHEQUE, $year);

            $clientReceivableCode = SalesAccounts::receivableFor($client);

            // Client collection reduces the receivable balance.
            $client->current_balance = round((float) $client->current_balance - $amount, 4);
            $client->save();

            $entryDescription = "استلام شيك {$chequeNumberInternal}: شيك رقم {$chequeNo} - {$bankName}";
            $memo = Str::limit("شيك رقم {$chequeNo} - {$bankName}", 250);

            $chequeId = (string) Str::uuid();

            $journalEntry = $this->accountingService->postJournal(
                description: $entryDescription,
                lines: [
                    [
                        'account_code' => LedgerAccounts::NOTES_RECEIVABLE,
                        'operating_unit_id' => $operatingUnitId,
                        'debit' => $amount,
                        'memo' => $memo,
                    ],
                    [
                        'account_code' => $clientReceivableCode,
                        'operating_unit_id' => $operatingUnitId,
                        'credit' => $amount,
                        'client_id' => $client->id,
                        'memo' => $memo,
                    ],
                ],
                sourceType: 'Cheque',
                sourceId: $chequeId,
                companyId: $companyId,
                isManual: false,
                userId: $user->id,
                entryDate: $receivedDate,
            );

            $cheque = Cheque::create([
                'id' => $chequeId,
                'company_id' => $companyId,
                'operating_unit_id' => $operatingUnitId,
                'cheque_number_internal' => $chequeNumberInternal,
                'direction' => $direction,
                'cheque_no' => $chequeNo,
                'bank_name' => $bankName,
                'drawer_name' => isset($data['drawer_name']) && trim((string) $data['drawer_name']) !== '' ? trim((string) $data['drawer_name']) : null,
                'client_id' => $client->id,
                'amount' => $amount,
                'currency' => 'LYD',
                'issue_date' => ! empty($data['issue_date']) ? $data['issue_date'] : null,
                'due_date' => $dueDate,
                'received_date' => $receivedDate,
                'is_certified' => (bool) ($data['is_certified'] ?? false),
                'status' => ChequeStatus::InPortfolio,
                'deposit_cash_account_id' => null,
                'status_changed_at' => now(),
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'bounce_reason' => null,
                'created_by_user_id' => $user->id,
                'record_version' => 1,
            ]);

            ChequeEvent::create([
                'id' => (string) Str::uuid(),
                'cheque_id' => $cheque->id,
                'from_status' => null,
                'to_status' => ChequeStatus::InPortfolio,
                'event_date' => $receivedDate,
                'journal_entry_id' => $journalEntry->id,
                'user_id' => $user->id,
                'note' => $cheque->notes,
            ]);

            return $cheque;
        });
    }

    /**
     * Deposit a cheque into a bank treasury under collection.
     *
     * @throws LedgerRuleException
     */
    public function deposit(Cheque $cheque, string $bankCashAccountId, ?string $date, User $user, ?string $note = null): Cheque
    {
        return DB::transaction(function () use ($cheque, $bankCashAccountId, $date, $user, $note): Cheque {
            $lockedCheque = Cheque::whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedCheque->direction !== 'incoming' || ($lockedCheque->status !== ChequeStatus::InPortfolio && $lockedCheque->status !== ChequeStatus::Bounced)) {
                throw new LedgerRuleException(
                    "Cheque {$lockedCheque->cheque_number_internal} cannot move from {$lockedCheque->status->value} to deposited.",
                    'INVALID_CHEQUE_TRANSITION',
                    ['from' => $lockedCheque->status->value, 'to' => ChequeStatus::Deposited->value],
                );
            }

            $eventDate = $date !== null && trim($date) !== '' ? (string) $date : CompanyClock::today();
            if (Carbon::parse($eventDate)->gt(Carbon::parse(CompanyClock::today()))) {
                throw new LedgerRuleException('The event date cannot be in the future.', 'CHEQUE_DATE_IN_FUTURE');
            }

            $lastEventDate = $lockedCheque->events()->max('event_date') ?? $lockedCheque->received_date?->toDateString();
            if ($lastEventDate !== null && Carbon::parse($eventDate)->lt(Carbon::parse($lastEventDate))) {
                throw new LedgerRuleException('The event date cannot be before the cheque\'s last event date.', 'CHEQUE_DATE_BEFORE_LAST_EVENT');
            }

            $bankAccount = CashAccount::with('account')->lockForUpdate()->find($bankCashAccountId);
            if ($bankAccount === null || $bankAccount->kind !== CashAccount::KIND_BANK) {
                throw new LedgerRuleException('The selected treasury must be a bank account.', 'CHEQUE_DEPOSIT_NOT_BANK');
            }

            $amount = (float) $lockedCheque->amount;
            $operatingUnitId = $lockedCheque->operating_unit_id;
            $fromStatus = $lockedCheque->status;

            if ($fromStatus === ChequeStatus::InPortfolio) {
                $lines = [
                    [
                        'account_code' => LedgerAccounts::CHEQUES_UNDER_COLLECTION,
                        'operating_unit_id' => $operatingUnitId,
                        'debit' => $amount,
                        'memo' => "إيداع برسم التحصيل لدى {$bankAccount->name}",
                    ],
                    [
                        'account_code' => LedgerAccounts::NOTES_RECEIVABLE,
                        'operating_unit_id' => $operatingUnitId,
                        'credit' => $amount,
                        'memo' => "إيداع برسم التحصيل لدى {$bankAccount->name}",
                    ],
                ];
                $entryDesc = "إيداع شيك {$lockedCheque->cheque_number_internal} برسم التحصيل لدى {$bankAccount->name}";
            } else {
                // From Bounced (redeposit)
                $client = Client::whereKey($lockedCheque->client_id)->lockForUpdate()->firstOrFail();
                $clientReceivableCode = SalesAccounts::receivableFor($client);

                $client->current_balance = round((float) $client->current_balance - $amount, 4);
                $client->save();

                $lines = [
                    [
                        'account_code' => LedgerAccounts::CHEQUES_UNDER_COLLECTION,
                        'operating_unit_id' => $operatingUnitId,
                        'debit' => $amount,
                        'memo' => "إعادة إيداع شيك مرتد برسم التحصيل لدى {$bankAccount->name}",
                    ],
                    [
                        'account_code' => $clientReceivableCode,
                        'operating_unit_id' => $operatingUnitId,
                        'credit' => $amount,
                        'client_id' => $client->id,
                        'memo' => "إعادة إيداع شيك مرتد برسم التحصيل لدى {$bankAccount->name}",
                    ],
                ];
                $entryDesc = "إعادة إيداع شيك مرتد {$lockedCheque->cheque_number_internal} لدى {$bankAccount->name}";
            }

            $journalEntry = $this->accountingService->postJournal(
                description: $entryDesc,
                lines: $lines,
                sourceType: 'Cheque',
                sourceId: $lockedCheque->id,
                companyId: $lockedCheque->company_id,
                isManual: false,
                userId: $user->id,
                entryDate: $eventDate,
            );

            $lockedCheque->status = ChequeStatus::Deposited;
            $lockedCheque->deposit_cash_account_id = $bankAccount->id;
            $lockedCheque->status_changed_at = now();
            $lockedCheque->save();

            ChequeEvent::create([
                'id' => (string) Str::uuid(),
                'cheque_id' => $lockedCheque->id,
                'from_status' => $fromStatus,
                'to_status' => ChequeStatus::Deposited,
                'event_date' => $eventDate,
                'journal_entry_id' => $journalEntry->id,
                'user_id' => $user->id,
                'note' => $note,
            ]);

            return $lockedCheque;
        });
    }

    /**
     * Clear a cheque into a bank treasury.
     *
     * @throws LedgerRuleException
     */
    public function clear(Cheque $cheque, ?string $date, User $user, ?string $bankCashAccountId = null, ?string $note = null): Cheque
    {
        return DB::transaction(function () use ($cheque, $date, $user, $bankCashAccountId, $note): Cheque {
            $lockedCheque = Cheque::whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            $fromStatus = $lockedCheque->status;
            if ($lockedCheque->direction !== 'incoming' || ($fromStatus !== ChequeStatus::Deposited && $fromStatus !== ChequeStatus::InPortfolio)) {
                throw new LedgerRuleException(
                    "Cheque {$lockedCheque->cheque_number_internal} cannot move from {$lockedCheque->status->value} to cleared.",
                    'INVALID_CHEQUE_TRANSITION',
                    ['from' => $lockedCheque->status->value, 'to' => ChequeStatus::Cleared->value],
                );
            }

            if ($fromStatus === ChequeStatus::InPortfolio && ! $lockedCheque->is_certified) {
                throw new LedgerRuleException('Only certified cheques can be cleared directly from portfolio.', 'CHEQUE_NOT_CERTIFIED');
            }

            $eventDate = $date !== null && trim($date) !== '' ? (string) $date : CompanyClock::today();
            if (Carbon::parse($eventDate)->gt(Carbon::parse(CompanyClock::today()))) {
                throw new LedgerRuleException('The event date cannot be in the future.', 'CHEQUE_DATE_IN_FUTURE');
            }

            $lastEventDate = $lockedCheque->events()->max('event_date') ?? $lockedCheque->received_date?->toDateString();
            if ($lastEventDate !== null && Carbon::parse($eventDate)->lt(Carbon::parse($lastEventDate))) {
                throw new LedgerRuleException('The event date cannot be before the cheque\'s last event date.', 'CHEQUE_DATE_BEFORE_LAST_EVENT');
            }

            // A deposited cheque clears into the bank it was deposited in; only a certified
            // cheque cleared straight from the portfolio names its bank here.
            $bankId = $fromStatus === ChequeStatus::Deposited
                ? $lockedCheque->deposit_cash_account_id
                : $bankCashAccountId;
            $bankAccount = $bankId !== null ? CashAccount::with('account')->lockForUpdate()->find($bankId) : null;

            if ($bankAccount === null || $bankAccount->kind !== CashAccount::KIND_BANK) {
                throw new LedgerRuleException('The selected treasury must be a bank account.', 'CHEQUE_DEPOSIT_NOT_BANK');
            }

            $bankCode = TreasuryAccounts::codeFor($bankAccount);
            $amount = (float) $lockedCheque->amount;
            $operatingUnitId = $lockedCheque->operating_unit_id;

            $creditAccount = $fromStatus === ChequeStatus::Deposited
                ? LedgerAccounts::CHEQUES_UNDER_COLLECTION
                : LedgerAccounts::NOTES_RECEIVABLE;

            $entryDesc = "تحصيل شيك {$lockedCheque->cheque_number_internal}: شيك رقم {$lockedCheque->cheque_no} - {$bankAccount->name}";
            $memo = "تحصيل شيك {$lockedCheque->cheque_number_internal}";

            $journalEntry = $this->accountingService->postJournal(
                description: $entryDesc,
                lines: [
                    [
                        'account_code' => $bankCode,
                        'operating_unit_id' => $operatingUnitId,
                        'debit' => $amount,
                        'memo' => $memo,
                    ],
                    [
                        'account_code' => $creditAccount,
                        'operating_unit_id' => $operatingUnitId,
                        'credit' => $amount,
                        'memo' => $memo,
                    ],
                ],
                sourceType: 'Cheque',
                sourceId: $lockedCheque->id,
                companyId: $lockedCheque->company_id,
                isManual: false,
                userId: $user->id,
                entryDate: $eventDate,
            );

            $lockedCheque->status = ChequeStatus::Cleared;
            $lockedCheque->deposit_cash_account_id = $bankAccount->id;
            $lockedCheque->status_changed_at = now();
            $lockedCheque->save();

            ChequeEvent::create([
                'id' => (string) Str::uuid(),
                'cheque_id' => $lockedCheque->id,
                'from_status' => $fromStatus,
                'to_status' => ChequeStatus::Cleared,
                'event_date' => $eventDate,
                'journal_entry_id' => $journalEntry->id,
                'user_id' => $user->id,
                'note' => $note,
            ]);

            return $lockedCheque;
        });
    }

    /**
     * Mark a cheque as bounced and restore the client's receivable.
     *
     * @throws LedgerRuleException
     */
    public function bounce(Cheque $cheque, ?string $date, string $reason, User $user): Cheque
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new LedgerRuleException('A bounce reason is required.', 'BOUNCE_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($cheque, $date, $reason, $user): Cheque {
            $lockedCheque = Cheque::whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            $fromStatus = $lockedCheque->status;
            if ($lockedCheque->direction !== 'incoming' || ($fromStatus !== ChequeStatus::Deposited && $fromStatus !== ChequeStatus::InPortfolio)) {
                throw new LedgerRuleException(
                    "Cheque {$lockedCheque->cheque_number_internal} cannot move from {$lockedCheque->status->value} to bounced.",
                    'INVALID_CHEQUE_TRANSITION',
                    ['from' => $lockedCheque->status->value, 'to' => ChequeStatus::Bounced->value],
                );
            }

            $eventDate = $date !== null && trim($date) !== '' ? (string) $date : CompanyClock::today();
            if (Carbon::parse($eventDate)->gt(Carbon::parse(CompanyClock::today()))) {
                throw new LedgerRuleException('The event date cannot be in the future.', 'CHEQUE_DATE_IN_FUTURE');
            }

            $lastEventDate = $lockedCheque->events()->max('event_date') ?? $lockedCheque->received_date?->toDateString();
            if ($lastEventDate !== null && Carbon::parse($eventDate)->lt(Carbon::parse($lastEventDate))) {
                throw new LedgerRuleException('The event date cannot be before the cheque\'s last event date.', 'CHEQUE_DATE_BEFORE_LAST_EVENT');
            }

            $client = Client::whereKey($lockedCheque->client_id)->lockForUpdate()->firstOrFail();
            $clientReceivableCode = SalesAccounts::receivableFor($client);

            $amount = (float) $lockedCheque->amount;
            $operatingUnitId = $lockedCheque->operating_unit_id;

            // Current balance goes UP by amount (client owes the money again).
            $client->current_balance = round((float) $client->current_balance + $amount, 4);
            $client->save();

            $creditAccount = $fromStatus === ChequeStatus::Deposited
                ? LedgerAccounts::CHEQUES_UNDER_COLLECTION
                : LedgerAccounts::NOTES_RECEIVABLE;

            $entryDesc = "ارتجاع شيك {$lockedCheque->cheque_number_internal}: {$reason}";
            $memo = "ارتجاع شيك {$lockedCheque->cheque_number_internal}: {$reason}";

            $journalEntry = $this->accountingService->postJournal(
                description: $entryDesc,
                lines: [
                    [
                        'account_code' => $clientReceivableCode,
                        'operating_unit_id' => $operatingUnitId,
                        'debit' => $amount,
                        'client_id' => $client->id,
                        'memo' => $memo,
                    ],
                    [
                        'account_code' => $creditAccount,
                        'operating_unit_id' => $operatingUnitId,
                        'credit' => $amount,
                        'memo' => $memo,
                    ],
                ],
                sourceType: 'Cheque',
                sourceId: $lockedCheque->id,
                companyId: $lockedCheque->company_id,
                isManual: false,
                userId: $user->id,
                entryDate: $eventDate,
            );

            $lockedCheque->status = ChequeStatus::Bounced;
            $lockedCheque->bounce_reason = $reason;
            $lockedCheque->status_changed_at = now();
            $lockedCheque->save();

            ChequeEvent::create([
                'id' => (string) Str::uuid(),
                'cheque_id' => $lockedCheque->id,
                'from_status' => $fromStatus,
                'to_status' => ChequeStatus::Bounced,
                'event_date' => $eventDate,
                'journal_entry_id' => $journalEntry->id,
                'user_id' => $user->id,
                'note' => $reason,
            ]);

            return $lockedCheque;
        });
    }

    /**
     * Return a cheque from portfolio back to its drawer.
     *
     * @throws LedgerRuleException
     */
    public function returnToDrawer(Cheque $cheque, ?string $date, ?string $note, User $user): Cheque
    {
        return DB::transaction(function () use ($cheque, $date, $note, $user): Cheque {
            $lockedCheque = Cheque::whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            $fromStatus = $lockedCheque->status;
            if ($lockedCheque->direction !== 'incoming' || $fromStatus !== ChequeStatus::InPortfolio) {
                throw new LedgerRuleException(
                    "Cheque {$lockedCheque->cheque_number_internal} cannot move from {$lockedCheque->status->value} to returned.",
                    'INVALID_CHEQUE_TRANSITION',
                    ['from' => $lockedCheque->status->value, 'to' => ChequeStatus::ReturnedToDrawer->value],
                );
            }

            $eventDate = $date !== null && trim($date) !== '' ? (string) $date : CompanyClock::today();
            if (Carbon::parse($eventDate)->gt(Carbon::parse(CompanyClock::today()))) {
                throw new LedgerRuleException('The event date cannot be in the future.', 'CHEQUE_DATE_IN_FUTURE');
            }

            $lastEventDate = $lockedCheque->events()->max('event_date') ?? $lockedCheque->received_date?->toDateString();
            if ($lastEventDate !== null && Carbon::parse($eventDate)->lt(Carbon::parse($lastEventDate))) {
                throw new LedgerRuleException('The event date cannot be before the cheque\'s last event date.', 'CHEQUE_DATE_BEFORE_LAST_EVENT');
            }

            $client = Client::whereKey($lockedCheque->client_id)->lockForUpdate()->firstOrFail();
            $clientReceivableCode = SalesAccounts::receivableFor($client);

            $amount = (float) $lockedCheque->amount;
            $operatingUnitId = $lockedCheque->operating_unit_id;

            // Current balance goes UP.
            $client->current_balance = round((float) $client->current_balance + $amount, 4);
            $client->save();

            $entryDesc = "إعادة شيك {$lockedCheque->cheque_number_internal} إلى الساحب".($note !== null && $note !== '' ? ": {$note}" : '');
            $memo = "إعادة شيك {$lockedCheque->cheque_number_internal} إلى الساحب";

            $journalEntry = $this->accountingService->postJournal(
                description: $entryDesc,
                lines: [
                    [
                        'account_code' => $clientReceivableCode,
                        'operating_unit_id' => $operatingUnitId,
                        'debit' => $amount,
                        'client_id' => $client->id,
                        'memo' => $memo,
                    ],
                    [
                        'account_code' => LedgerAccounts::NOTES_RECEIVABLE,
                        'operating_unit_id' => $operatingUnitId,
                        'credit' => $amount,
                        'memo' => $memo,
                    ],
                ],
                sourceType: 'Cheque',
                sourceId: $lockedCheque->id,
                companyId: $lockedCheque->company_id,
                isManual: false,
                userId: $user->id,
                entryDate: $eventDate,
            );

            $lockedCheque->status = ChequeStatus::ReturnedToDrawer;
            $lockedCheque->status_changed_at = now();
            $lockedCheque->save();

            ChequeEvent::create([
                'id' => (string) Str::uuid(),
                'cheque_id' => $lockedCheque->id,
                'from_status' => $fromStatus,
                'to_status' => ChequeStatus::ReturnedToDrawer,
                'event_date' => $eventDate,
                'journal_entry_id' => $journalEntry->id,
                'user_id' => $user->id,
                'note' => $note,
            ]);

            return $lockedCheque;
        });
    }

    /**
     * Issue an outgoing cheque drawn on a bank treasury.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LedgerRuleException
     */
    public function issue(array $data, User $user): Cheque
    {
        return DB::transaction(function () use ($data, $user): Cheque {
            $direction = (string) ($data['direction'] ?? 'outgoing');
            if ($direction !== 'outgoing') {
                throw new LedgerRuleException("Direction {$direction} is not supported in this method.", 'UNSUPPORTED_DIRECTION');
            }

            $cashAccountId = $data['cash_account_id'] ?? null;
            if ($cashAccountId === null || trim((string) $cashAccountId) === '') {
                throw new LedgerRuleException('A bank treasury is required for outgoing cheques.', 'CHEQUE_BANK_REQUIRED');
            }

            $bankAccount = CashAccount::with('account')->lockForUpdate()->find($cashAccountId);
            if ($bankAccount === null || $bankAccount->kind !== CashAccount::KIND_BANK) {
                throw new LedgerRuleException('The selected treasury must be a bank account.', 'CHEQUE_BANK_REQUIRED');
            }

            $currency = strtoupper(trim((string) ($data['currency'] ?? 'LYD')));
            if ($currency !== 'LYD') {
                throw new LedgerRuleException('Foreign currency cheques are not supported in this package.', 'CHEQUE_CURRENCY_UNSUPPORTED');
            }

            $amount = round((float) ($data['amount'] ?? 0), 4);
            if ($amount <= 0) {
                throw new LedgerRuleException('A cheque amount must be greater than zero.', 'INVALID_AMOUNT');
            }

            $chequeNo = trim((string) ($data['cheque_no'] ?? ''));
            if ($chequeNo === '') {
                throw new LedgerRuleException('A cheque number is required.', 'CHEQUE_NO_REQUIRED');
            }

            $dueDate = $data['due_date'] ?? null;
            if (empty($dueDate)) {
                throw new LedgerRuleException('A due date is required for cheques.', 'DUE_DATE_REQUIRED');
            }

            $issueDate = isset($data['issue_date']) && trim((string) $data['issue_date']) !== ''
                ? (string) $data['issue_date']
                : CompanyClock::today();

            if (Carbon::parse($issueDate)->gt(Carbon::parse(CompanyClock::today()))) {
                throw new LedgerRuleException('The event date cannot be in the future.', 'CHEQUE_DATE_IN_FUTURE');
            }

            $supplierId = $data['supplier_id'] ?? null;
            $supplier = null;
            $payeeName = null;

            if ($supplierId !== null) {
                $supplier = Supplier::with('account')->lockForUpdate()->find($supplierId);
                if ($supplier === null) {
                    throw new LedgerRuleException('Supplier not found.', 'SUPPLIER_NOT_FOUND');
                }

                if ($supplier->account === null) {
                    throw new LedgerRuleException('Supplier has no linked ledger account.', 'SUPPLIER_NOT_LINKED');
                }

                $counterAccountCode = $supplier->account->account_code;
                $payeeName = isset($data['payee_name']) && trim((string) $data['payee_name']) !== ''
                    ? trim((string) $data['payee_name'])
                    : null;
                $payeeDisplay = $payeeName ?? $supplier->name;
            } else {
                $counterAccountCode = isset($data['counter_account_code']) ? trim((string) $data['counter_account_code']) : '';
                $payeeName = isset($data['payee_name']) && trim((string) $data['payee_name']) !== ''
                    ? trim((string) $data['payee_name'])
                    : null;

                if ($counterAccountCode === '' || $payeeName === null) {
                    throw new LedgerRuleException('A supplier or both a counter account and payee name are required.', 'PAYEE_REQUIRED');
                }

                $counterAccount = Account::where('account_code', $counterAccountCode)->first();
                if ($counterAccount === null) {
                    throw new LedgerRuleException("Counter account {$counterAccountCode} does not exist.", 'ACCOUNT_NOT_FOUND');
                }

                if ($counterAccount->isFrozen()) {
                    throw new LedgerRuleException(
                        "Account {$counterAccount->account_code} ({$counterAccount->name}) is frozen and takes no new postings.",
                        'ACCOUNT_FROZEN',
                        ['account_code' => $counterAccount->account_code],
                    );
                }

                if (Account::where('parent_account_id', $counterAccount->id)->exists()) {
                    throw new HeaderAccountPostingException($counterAccount);
                }

                $isTreasury = in_array($counterAccount->id, AccountTree::subtreeIds('121'), true)
                    || CashAccount::where('account_id', $counterAccount->id)->exists();

                if ($isTreasury) {
                    throw new LedgerRuleException(
                        "Account {$counterAccountCode} is a treasury account; use a treasury transfer instead.",
                        'CHEQUE_COUNTER_IS_TREASURY',
                    );
                }

                $payeeDisplay = $payeeName;
            }

            $companyId = $data['company_id'] ?? Company::query()->value('id');
            if ($companyId === null) {
                throw new LedgerRuleException('No company exists to post a cheque against.', 'NO_COMPANY');
            }

            $operatingUnitId = $data['operating_unit_id']
                ?? $supplier?->operating_unit_id
                ?? $bankAccount->operating_unit_id
                ?? OperatingUnit::query()->value('id');

            if ($operatingUnitId === null) {
                throw new LedgerRuleException('Operating unit is required.', 'OPERATING_UNIT_REQUIRED');
            }

            $year = (int) Carbon::parse($issueDate)->format('Y');
            $chequeNumberInternal = $this->documentNumbers->next(DocumentNumberService::CHEQUE, $year);

            $entryDescription = "إصدار شيك {$chequeNumberInternal}: شيك رقم {$chequeNo} - {$bankAccount->name} - {$payeeDisplay}";
            $memo = Str::limit("شيك صادر رقم {$chequeNo} - {$payeeDisplay}", 250);

            $chequeId = (string) Str::uuid();

            $journalEntry = $this->accountingService->postJournal(
                description: $entryDescription,
                lines: [
                    [
                        'account_code' => $counterAccountCode,
                        'operating_unit_id' => $operatingUnitId,
                        'debit' => $amount,
                        'memo' => $memo,
                    ],
                    [
                        'account_code' => LedgerAccounts::NOTES_PAYABLE,
                        'operating_unit_id' => $operatingUnitId,
                        'credit' => $amount,
                        'memo' => $memo,
                    ],
                ],
                sourceType: 'Cheque',
                sourceId: $chequeId,
                companyId: $companyId,
                isManual: false,
                userId: $user->id,
                entryDate: $issueDate,
            );

            $cheque = Cheque::create([
                'id' => $chequeId,
                'company_id' => $companyId,
                'operating_unit_id' => $operatingUnitId,
                'cheque_number_internal' => $chequeNumberInternal,
                'direction' => 'outgoing',
                'cheque_no' => $chequeNo,
                'bank_name' => $bankAccount->name,
                'drawer_name' => null,
                'client_id' => null,
                'supplier_id' => $supplier?->id,
                'payee_name' => $payeeName,
                'counter_account_code' => $counterAccountCode,
                'amount' => $amount,
                'currency' => 'LYD',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'received_date' => $issueDate,
                'is_certified' => false,
                'status' => ChequeStatus::Issued,
                'deposit_cash_account_id' => $bankAccount->id,
                'status_changed_at' => now(),
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'bounce_reason' => null,
                'issue_journal_entry_id' => $journalEntry->id,
                'created_by_user_id' => $user->id,
                'record_version' => 1,
            ]);

            ChequeEvent::create([
                'id' => (string) Str::uuid(),
                'cheque_id' => $cheque->id,
                'from_status' => null,
                'to_status' => ChequeStatus::Issued,
                'event_date' => $issueDate,
                'journal_entry_id' => $journalEntry->id,
                'user_id' => $user->id,
                'note' => $cheque->notes,
            ]);

            return $cheque;
        });
    }

    /**
     * Clear an issued outgoing cheque from the drawn-on bank.
     *
     * @throws LedgerRuleException
     */
    public function clearIssued(Cheque $cheque, ?string $date, User $user, ?string $note = null): Cheque
    {
        return DB::transaction(function () use ($cheque, $date, $user, $note): Cheque {
            $lockedCheque = Cheque::whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedCheque->direction !== 'outgoing' || $lockedCheque->status !== ChequeStatus::Issued) {
                throw new LedgerRuleException(
                    "Cheque {$lockedCheque->cheque_number_internal} cannot move from {$lockedCheque->status->value} to cleared.",
                    'INVALID_CHEQUE_TRANSITION',
                    ['from' => $lockedCheque->status->value, 'to' => ChequeStatus::Cleared->value],
                );
            }

            $eventDate = $date !== null && trim($date) !== '' ? (string) $date : CompanyClock::today();
            if (Carbon::parse($eventDate)->gt(Carbon::parse(CompanyClock::today()))) {
                throw new LedgerRuleException('The event date cannot be in the future.', 'CHEQUE_DATE_IN_FUTURE');
            }

            $lastEventDate = $lockedCheque->events()->max('event_date') ?? $lockedCheque->issue_date?->toDateString();
            if ($lastEventDate !== null && Carbon::parse($eventDate)->lt(Carbon::parse($lastEventDate))) {
                throw new LedgerRuleException('The event date cannot be before the cheque\'s last event date.', 'CHEQUE_DATE_BEFORE_LAST_EVENT');
            }

            $bankAccount = CashAccount::with('account')->lockForUpdate()->find($lockedCheque->deposit_cash_account_id);
            if ($bankAccount === null || $bankAccount->kind !== CashAccount::KIND_BANK) {
                throw new LedgerRuleException('The selected treasury must be a bank account.', 'CHEQUE_BANK_REQUIRED');
            }

            $balance = $this->balanceService->balanceFor($bankAccount);
            $available = round((float) ($balance['ledger_balance'] ?? 0.0), 4);
            if ($available < (float) $lockedCheque->amount) {
                throw new LedgerRuleException(
                    "Insufficient balance in treasury {$bankAccount->name}. Available: {$available}, required: {$lockedCheque->amount}.",
                    'TREASURY_INSUFFICIENT_BALANCE',
                    ['available' => $available],
                );
            }

            $bankCode = TreasuryAccounts::codeFor($bankAccount);
            $amount = (float) $lockedCheque->amount;
            $operatingUnitId = $lockedCheque->operating_unit_id;

            $entryDesc = "صرف شيك {$lockedCheque->cheque_number_internal}: شيك رقم {$lockedCheque->cheque_no} - {$bankAccount->name}";
            $memo = "صرف شيك {$lockedCheque->cheque_number_internal}";

            $journalEntry = $this->accountingService->postJournal(
                description: $entryDesc,
                lines: [
                    [
                        'account_code' => LedgerAccounts::NOTES_PAYABLE,
                        'operating_unit_id' => $operatingUnitId,
                        'debit' => $amount,
                        'memo' => $memo,
                    ],
                    [
                        'account_code' => $bankCode,
                        'operating_unit_id' => $operatingUnitId,
                        'credit' => $amount,
                        'memo' => $memo,
                    ],
                ],
                sourceType: 'Cheque',
                sourceId: $lockedCheque->id,
                companyId: $lockedCheque->company_id,
                isManual: false,
                userId: $user->id,
                entryDate: $eventDate,
            );

            $lockedCheque->status = ChequeStatus::Cleared;
            $lockedCheque->status_changed_at = now();
            $lockedCheque->save();

            ChequeEvent::create([
                'id' => (string) Str::uuid(),
                'cheque_id' => $lockedCheque->id,
                'from_status' => ChequeStatus::Issued,
                'to_status' => ChequeStatus::Cleared,
                'event_date' => $eventDate,
                'journal_entry_id' => $journalEntry->id,
                'user_id' => $user->id,
                'note' => $note,
            ]);

            return $lockedCheque;
        });
    }

    /**
     * Cancel an issued outgoing cheque and reverse its issue journal entry.
     *
     * @throws LedgerRuleException
     */
    public function cancelIssued(Cheque $cheque, string $reason, User $user): Cheque
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new LedgerRuleException('A cancellation reason is required.', 'CANCEL_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($cheque, $reason, $user): Cheque {
            $lockedCheque = Cheque::whereKey($cheque->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedCheque->direction !== 'outgoing' || $lockedCheque->status !== ChequeStatus::Issued) {
                throw new LedgerRuleException(
                    "Cheque {$lockedCheque->cheque_number_internal} cannot move from {$lockedCheque->status->value} to cancelled.",
                    'INVALID_CHEQUE_TRANSITION',
                    ['from' => $lockedCheque->status->value, 'to' => ChequeStatus::Cancelled->value],
                );
            }

            $issueEntry = $lockedCheque->issue_journal_entry_id !== null
                ? JournalEntry::whereKey($lockedCheque->issue_journal_entry_id)->first()
                : null;

            if ($issueEntry === null) {
                throw new LedgerRuleException('Issue journal entry not found.', 'ENTRY_NOT_FOUND');
            }

            $cancelEntry = $this->accountingService->reverseEntry(
                $issueEntry,
                CompanyClock::today(),
                $reason,
                $user->id,
            );

            $lockedCheque->status = ChequeStatus::Cancelled;
            $lockedCheque->status_changed_at = now();
            $lockedCheque->save();

            ChequeEvent::create([
                'id' => (string) Str::uuid(),
                'cheque_id' => $lockedCheque->id,
                'from_status' => ChequeStatus::Issued,
                'to_status' => ChequeStatus::Cancelled,
                'event_date' => CompanyClock::today(),
                'journal_entry_id' => $cancelEntry->id,
                'user_id' => $user->id,
                'note' => $reason,
            ]);

            return $lockedCheque;
        });
    }
}
