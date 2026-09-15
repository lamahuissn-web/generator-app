<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reading a subscriber's meter was already showing when the office took him on.
 *
 * Without it the first cycle bills the WHOLE meter: the panel resolves «the previous reading» as the
 * subscriber's last reading or 0, and a household that arrived from a paper ledger with 12,500 on the
 * dial was billed 12,600 kWh — $3,150 instead of $25 — the first time anybody read it.
 *
 * Optional on purpose: a brand-new meter starts at zero, and leaving these empty keeps the old
 * behaviour exactly. The month is what the reading is dated to; a later first reading measures from
 * here, which is the truth of what the meter has run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->decimal('opening_reading', 12, 2)->nullable()->after('amperage');
            $table->string('opening_period', 7)->nullable()->after('opening_reading');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['opening_reading', 'opening_period']);
        });
    }
};
