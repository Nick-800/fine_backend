<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Journal entry references (JE-YYYY-NNNNN) now come from the locked
 * document_sequences counter. Existing databases already hold references, so
 * each year's counter starts at the highest number issued so far — otherwise
 * the next posting would reuse JE-YYYY-00001 and hit the unique constraint.
 *
 * The suffix is parsed in PHP so the migration runs the same on SQLite, MySQL
 * and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $highest = [];

        DB::table('journal_entries')->select('reference')->orderBy('id')->chunk(1000, function ($rows) use (&$highest): void {
            foreach ($rows as $row) {
                if (preg_match('/^JE-(\d{4})-(\d+)$/', (string) $row->reference, $match) !== 1) {
                    continue;
                }

                $year = (int) $match[1];
                $highest[$year] = max($highest[$year] ?? 0, (int) $match[2]);
            }
        });

        foreach ($highest as $year => $lastNumber) {
            $existing = DB::table('document_sequences')
                ->where('document_type', 'JE')
                ->where('year', $year)
                ->first();

            if ($existing === null) {
                DB::table('document_sequences')->insert([
                    'id' => (string) Str::uuid(),
                    'document_type' => 'JE',
                    'year' => $year,
                    'last_number' => $lastNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            if ((int) $existing->last_number < $lastNumber) {
                DB::table('document_sequences')
                    ->where('id', $existing->id)
                    ->update(['last_number' => $lastNumber, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('document_sequences')->where('document_type', 'JE')->delete();
    }
};
