<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The destroy endpoint exists now; deletion is soft like the sibling
        // employee/client records, not a hard row drop.
        Schema::table('external_employers', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('external_employers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
