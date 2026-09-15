<?php

declare(strict_types=1);

namespace Database\Seeders\Dummy;

use App\Enums\AllocationMethod;
use App\Enums\OverheadCategory;
use App\Enums\OverheadExpenseStatus;
use App\Enums\OverheadPaymentSource;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\OverheadAllocationRule;
use App\Models\OverheadExpense;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Overhead expenses across categories, statuses, and payment sources, plus
 * an active manual allocation rule that splits company overhead between the
 * manufacturing units.
 */
class OverheadSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();
        if ($company === null) {
            return;
        }

        $units = OperatingUnit::orderBy('name')->get();
        $treasuryOfficer = User::where('email', 'treasury@erp.com')->first();

        $categories = OverheadCategory::cases();
        $paymentSources = OverheadPaymentSource::cases();

        for ($i = 1; $i <= 8; $i++) {
            $unit = $units->random();
            OverheadExpense::create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'operating_unit_id' => $unit?->id,
                'category' => $categories[($i - 1) % count($categories)],
                'description' => fake()->sentence(5),
                'amount' => fake()->randomFloat(4, 200, 8000),
                'currency' => 'LYD',
                'expense_date' => fake()->dateTimeBetween('-60 days', 'now')->format('Y-m-d'),
                'payment_source' => $paymentSources[$i % count($paymentSources)],
                'status' => $i % 3 === 0 ? OverheadExpenseStatus::Allocated : OverheadExpenseStatus::Recorded,
                'allocated_at' => $i % 3 === 0 ? now() : null,
            ]);
        }

        // Manual-percentage allocation rule across units
        $manufacturingUnits = $units->filter(fn ($u) => $u->unit_type === 'manufactory');
        if ($manufacturingUnits->isNotEmpty()) {
            $percentages = [];
            $remaining = 100;
            foreach ($manufacturingUnits as $idx => $unit) {
                if ($idx === $manufacturingUnits->count() - 1) {
                    $percentages[$unit->id] = $remaining;
                } else {
                    $share = (int) floor(100 / $manufacturingUnits->count());
                    $percentages[$unit->id] = $share;
                    $remaining -= $share;
                }
            }

            OverheadAllocationRule::firstOrCreate(
                ['company_id' => $company->id, 'method' => AllocationMethod::ManualPercentage->value],
                [
                    'id' => (string) Str::uuid(),
                    'percentages' => $percentages,
                    'is_active' => true,
                ],
            );
        }

        // Suppress unused variable
        unset($treasuryOfficer);
    }
}
