<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignUuid('parent_id')->nullable()->after('operating_unit_id')
                ->constrained('warehouses')->nullOnDelete();
            $table->string('location_type')->nullable()->after('is_internal_unit');
        });

        // The "warehouses" and "storage-locations" reference-lookup categories are
        // superseded by real warehouse parent/child rows — drop the dead seed data.
        DB::table('reference_lookups')->whereIn('category', ['warehouses', 'storage-locations'])->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('location_type');
        });
    }
};
