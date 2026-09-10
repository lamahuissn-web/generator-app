<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Broken meters: an estimated cycle (the meter is dead, the operator decides the kWh)
 * and a replacement recorded on the cycle's single reading row (old meter's final
 * reading + new meter's start), so every report and printout keeps reading one row
 * per cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->boolean('is_estimated')->default(false)->after('consumption');
            $table->string('estimate_reason', 160)->nullable()->after('is_estimated');

            // Meter replacement, stored on the cycle's reading row.
            $table->string('old_meter_no', 40)->nullable()->after('estimate_reason');
            $table->decimal('old_meter_reading', 12, 2)->nullable()->after('old_meter_no');
            $table->string('new_meter_no', 40)->nullable()->after('old_meter_reading');
            $table->decimal('new_meter_start', 12, 2)->nullable()->after('new_meter_no');
        });

        Schema::table('subscribers', function (Blueprint $table) {
            // Field report from the collector ("this meter is dead"); the admin decides.
            $table->timestamp('meter_broken_reported_at')->nullable()->after('status');
            $table->string('meter_broken_note', 200)->nullable()->after('meter_broken_reported_at');
        });
    }

    public function down(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->dropColumn([
                'is_estimated', 'estimate_reason',
                'old_meter_no', 'old_meter_reading', 'new_meter_no', 'new_meter_start',
            ]);
        });

        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['meter_broken_reported_at', 'meter_broken_note']);
        });
    }
};
