<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lira a receipt actually took.
 *
 * Payments were dollars only: a collector who came back with 5,000,000 lira converted it in his
 * head, typed the dollar figure, and the lira was never written down. The office could not add up
 * how much lira a run had collected, nor check a collector's cash box against the money in it.
 *
 * Both columns are nullable and stay null for a receipt taken in dollars: "no lira" and "zero
 * lira" are different facts, and only the first one is true of a cash-USD collection.
 *
 * The amount is stored exactly as it was handed over, and the rate it was converted at is stored
 * beside it, because the rate moves: a receipt from August must keep August's number, not be
 * re-converted at whatever the rate is the day somebody prints it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // decimal, never float: this is money. 14 digits of lira is a billion dollars' worth.
            $table->decimal('lbp_amount', 14, 0)->nullable()->after('amount');
            // LBP per 1 USD, as used for THIS receipt.
            $table->decimal('lbp_rate', 12, 2)->nullable()->after('lbp_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['lbp_amount', 'lbp_rate']);
        });
    }
};
