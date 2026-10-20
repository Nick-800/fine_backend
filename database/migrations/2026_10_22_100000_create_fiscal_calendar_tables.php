<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fiscal years split into calendar months (FR-GL-044, ACC-11). A month is
 * locked by the accounting manager once it has ended; nothing posts into a
 * locked month or a closed year.
 *
 * Existing databases get open years covering every live entry through today.
 * Nothing is locked automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1)->after('timezone');
        });

        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10)->default('open')->index();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('closing_entry_id')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignUuid('reopened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reopen_reason', 500)->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('period_number');
            $table->unsignedSmallInteger('calendar_year');
            $table->unsignedTinyInteger('calendar_month');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10)->default('open')->index();
            $table->timestamp('locked_at')->nullable();
            $table->foreignUuid('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignUuid('reopened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reopen_reason', 500)->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'calendar_year', 'calendar_month']);
            $table->index(['fiscal_year_id', 'period_number']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('fiscal_year_start_month');
        });
    }

    /**
     * Open calendar-year fiscal years from the earliest live entry through the
     * year containing today, so every existing entry sits in an open month.
     */
    private function backfill(): void
    {
        foreach (DB::table('companies')->get(['id', 'timezone']) as $company) {
            $today = CarbonImmutable::now($company->timezone ?: 'Africa/Tripoli')->startOfDay();
            $earliest = DB::table('journal_entries')
                ->where('company_id', $company->id)
                ->whereNull('deleted_at')
                ->min('entry_date');
            $first = $earliest !== null ? CarbonImmutable::parse($earliest) : $today;

            for ($year = (int) $first->year; $year <= (int) $today->year; $year++) {
                $yearId = (string) Str::uuid();
                $now = now();

                DB::table('fiscal_years')->insert([
                    'id' => $yearId,
                    'company_id' => $company->id,
                    'code' => "FY{$year}",
                    'name' => (string) $year,
                    'start_date' => "{$year}-01-01",
                    'end_date' => "{$year}-12-31",
                    'status' => 'open',
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                for ($month = 1; $month <= 12; $month++) {
                    $start = CarbonImmutable::create($year, $month, 1);
                    DB::table('fiscal_periods')->insert([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'fiscal_year_id' => $yearId,
                        'period_number' => $month,
                        'calendar_year' => $year,
                        'calendar_month' => $month,
                        'start_date' => $start->toDateString(),
                        'end_date' => $start->endOfMonth()->toDateString(),
                        'status' => 'open',
                        'record_version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
};
