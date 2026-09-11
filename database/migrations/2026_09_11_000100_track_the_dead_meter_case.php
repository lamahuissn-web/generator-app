<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dead meter is a STATE OF THE DEVICE, and it needs to be tracked as one.
 *
 * Until now the only record was `meter_broken_reported_at`, and any recorded cycle cleared it —
 * including an estimate, which fixes nothing about the device. So the office estimated the dead
 * meter's cycle, the flag went out, and from the next cycle on every screen said the subscriber
 * was normal while the meter was still dead. This migration adds what the case needs to persist
 * and be decided:
 *
 *  - `meter_broken_reported_by`: who in the field reported it. A case is a conversation between
 *    the man at the door and the office, and the office needs to know who saw the meter.
 *  - `meter_broken_estimates`: how many estimated cycles have been billed while the meter has
 *    been dead. The policy allows one; the count is what makes the second estimate a decision
 *    instead of an accident. Stored rather than derived from the readings: a reading entered late
 *    for an older period would otherwise land on the wrong side of the report date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->unsignedTinyInteger('meter_broken_estimates')->default(0)->after('meter_broken_note');
            $table->foreignId('meter_broken_reported_by')->nullable()->after('meter_broken_estimates')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meter_broken_reported_by');
            $table->dropColumn('meter_broken_estimates');
        });
    }
};
