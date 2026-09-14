<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt gets the day the money was collected, not only the day it was typed in.
 *
 * `created_at` cannot answer "what did we collect in August": a receipt written on the 1st for a
 * collection made on the 31st belongs to August — to August's report, to August's receipt number,
 * and to the collector's own statement for that day. Every existing row is backfilled from
 * `created_at`, so nothing moves by deploying this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->date('collected_on')->nullable()->after('method');
        });

        DB::table('payments')->update(['collected_on' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('collected_on');
        });
    }
};
