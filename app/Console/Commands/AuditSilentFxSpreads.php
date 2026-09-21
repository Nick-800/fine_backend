<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wave 2 audit: list historical PaymentRequest rows where a Bank spread
 * (or rate-driven variance) exceeded the tolerance band without an
 * `extra_allocation_note`. These are the silent spreads the new
 * LYD-grounded variance check would now refuse.
 *
 * Read-only — surfaces findings only, does not mutate. Run with
 * `php artisan fx:audit-silent-spreads` (optionally `--tolerance=N`,
 * `--json`, `--unit=<uuid>`).
 */
final class AuditSilentFxSpreads extends Command
{
    protected $signature = 'fx:audit-silent-spreads
        {--tolerance=0.01 : LYD tolerance band; defaults to config(fine.fx.tolerance_lyd)}
        {--unit= : Restrict to a single operating_unit_id}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'List paid PaymentRequests where settled deviated from booked beyond the tolerance band without a justification note';

    public function handle(): int
    {
        $tolerance = (float) $this->option('tolerance');
        $unitId = $this->option('unit');

        $rows = DB::table('payment_requests as pr')
            ->join('import_orders as io', 'io.id', '=', 'pr.import_order_id')
            ->leftJoin('bank_holds as bh', 'bh.payment_request_id', '=', 'pr.id')
            ->where('pr.status', PaymentRequestStatus::Paid->value)
            ->whereNotNull('io.booked_fx_rate')
            ->whereNotNull('bh.exact_amount_used')
            ->when($unitId, fn ($q) => $q->where('io.operating_unit_id', $unitId))
            ->select([
                'pr.id as payment_request_id',
                'io.id as order_id',
                'io.operating_unit_id',
                'io.booked_fx_rate',
                'pr.amount_requested',
                'pr.fx_rate_used',
                'bh.exact_amount_used',
                'pr.extra_allocation_note',
                'pr.created_at',
            ])
            ->get()
            ->map(function ($row) use ($tolerance) {
                $expected = round((float) $row->booked_fx_rate * (float) $row->amount_requested, 4);
                $variance = round((float) $row->exact_amount_used - $expected, 4);

                $row->expected_lyd = $expected;
                $row->variance_lyd = $variance;
                $row->within_tolerance = abs($variance) <= $tolerance;
                $row->has_note = ! is_null($row->extra_allocation_note) && $row->extra_allocation_note !== '';

                return $row;
            })
            ->reject(fn ($row) => $row->within_tolerance || $row->has_note)
            ->sortByDesc(fn ($row) => abs($row->variance_lyd))
            ->values();

        if ($this->option('json')) {
            $this->line($rows->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->info('No silent FX spreads found above the '.$tolerance.' LYD tolerance band.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Found %d silent FX spread(s) above the %s LYD tolerance band:',
            $rows->count(),
            number_format($tolerance, 4),
        ));

        $this->table(
            ['PR id', 'Order', 'Unit', 'Booked', 'Amount', 'Settled LYD', 'Variance LYD', 'Note'],
            $rows->map(fn ($r) => [
                substr((string) $r->payment_request_id, 0, 8),
                substr((string) $r->order_id, 0, 8),
                substr((string) $r->operating_unit_id, 0, 8),
                number_format((float) $r->booked_fx_rate, 4),
                number_format((float) $r->amount_requested, 2),
                number_format((float) $r->exact_amount_used, 2),
                ($r->variance_lyd > 0 ? '+' : '').number_format((float) $r->variance_lyd, 2),
                '—',
            ])->all()
        );

        return self::SUCCESS;
    }
}
