<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rounding an invoice added to the subscriber's balance, written down so it can be taken back.
 *
 * A cycle that cost 51.60 and is charged at 52.00 leaves him 40 cents with the office
 * (BillingService::billCredit). Removing that invoice has to undo the whole movement it made, and the
 * movement cannot be recomputed from the row afterwards: the printed charge lines absorbed the
 * rounding, so `total − exact` is no longer knowable from the bill alone. It is therefore stored when
 * the bill is created — the same reason payments.applied is stored — so deleting an invoice is an
 * exact inverse instead of a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            // Default 0 reads as "this invoice added nothing to his balance": true of every row
            // written before the column existed, and of every invoice whose ceiling did not fire.
            $table->decimal('credit_created', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn('credit_created');
        });
    }
};
