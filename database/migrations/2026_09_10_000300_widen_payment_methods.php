<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash in USD/LBP and the two Lebanese payment rails are separate methods:
     * the collector has to say which one was used, and the cash box is settled
     * per method. The old enum('cash') column is widened to a plain string.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 20)->default('cash_usd')->change();
        });

        // Existing rows were plain cash; the demo collected USD.
        DB::table('payments')->where('method', 'cash')->update(['method' => 'cash_usd']);
    }

    public function down(): void
    {
        DB::table('payments')->whereIn('method', ['cash_usd', 'cash_lbp', 'whish', 'omt', 'other'])
            ->update(['method' => 'cash']);

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('method', ['cash'])->default('cash')->change();
        });
    }
};
