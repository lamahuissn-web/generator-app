<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The extra a subscriber pays, and the extra the office charges, are the subscriber's own money.
 *
 * The office's reality, in his words: a bill of 51.60 and a collector who has no change takes 52 —
 * a whole number, every month. Money taken above the exact charge used to vanish: the receipt
 * recorded only what settled bills, so the 40 cents lived in the collector's pocket and nowhere in
 * the books, and the next month the subscriber was charged the full amount again. The same thing
 * happened on the other side: rounding the bill up to 52.00 for a cycle that cost 51.60 handed the
 * office 40 cents that were never earned.
 *
 * So both differences are recorded as what they are — the subscriber's balance with the office:
 *
 *   subscribers.credit   his money, waiting. Applied to his next bill BEFORE the rounding, so it
 *                        comes back to him as a cheaper month (see BillingService::creditPlan).
 *   bills.credit_applied how much of it that invoice used, so the paper can add up on its own
 *                        terms (usage + fees − credit = total) and the statement can name it.
 *   payments.applied     what settled BILLS out of the cash received — the part that is actually
 *                        earned. `amount` stays the cash that changed hands, because that is what
 *                        the collector hands over and what the drawer holds; the difference between
 *                        the two is the credit above, which is why the books balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->decimal('credit', 12, 2)->default(0);
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->decimal('credit_applied', 12, 2)->default(0);
        });

        Schema::table('payments', function (Blueprint $table): void {
            // Nullable on purpose: rows written before this column was filled in are read as
            // "the whole receipt settled bills", which is what the old code recorded.
            $table->decimal('applied', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->dropColumn('credit');
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn('credit_applied');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('applied');
        });
    }
};
