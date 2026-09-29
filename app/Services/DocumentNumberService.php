<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-issued document numbers — sales (S), quotations (Q) and cutter work
 * orders (CWO). One counter per type and year serves every unit, and it is
 * locked while it moves, so two tills can never hand out the same number.
 */
final class DocumentNumberService
{
    public const SALE = 'S';

    public const QUOTATION = 'Q';

    public const CUTTER_WORK_ORDER = 'CWO';

    /**
     * The next number for a document type, e.g. S-2026-00042.
     */
    public function next(string $documentType): string
    {
        $year = (int) now()->format('Y');

        return DB::transaction(function () use ($documentType, $year): string {
            // Seed the year's row without racing a concurrent first request.
            DocumentSequence::query()->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'document_type' => $documentType,
                'year' => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DocumentSequence::where('document_type', $documentType)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->last_number++;
            $sequence->save();

            return sprintf('%s-%d-%05d', $documentType, $year, $sequence->last_number);
        });
    }
}
