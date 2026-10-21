<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Services\FiscalYearService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fiscal year closing preview, closing, and reopening (ACC-11, ACC-12).
 */
final class FiscalYearController extends Controller
{
    public function __construct(private readonly FiscalYearService $fiscalYears) {}

    public function closingPreview(string $id): JsonResponse
    {
        $year = FiscalYear::findOrFail($id);

        return response()->json($this->fiscalYears->preview($year));
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $year = FiscalYear::findOrFail($id);

        $closed = $this->fiscalYears->close($year, $request->user());

        return response()->json($closed);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $year = FiscalYear::findOrFail($id);

        $reopened = $this->fiscalYears->reopen($year, $request->user(), $validated['reason']);

        return response()->json($reopened);
    }
}
