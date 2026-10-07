<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reading the engine found suspicious, and somebody said it was right anyway (spec 013).
 *
 * The gate refuses to bill a jump or a zero until a person has looked; this column is the memory of that
 * look, so the office's board can show «أكّدها الجابي» on the rows where somebody waved it through — which
 * is exactly where a supervisor should glance first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readings', function (Blueprint $table): void {
            $table->boolean('anomaly_confirmed')->default(false)->after('is_estimated');
        });
    }

    public function down(): void
    {
        Schema::table('readings', function (Blueprint $table): void {
            $table->dropColumn('anomaly_confirmed');
        });
    }
};
