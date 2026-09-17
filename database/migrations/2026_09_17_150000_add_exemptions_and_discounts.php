<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is not charged, why, and for how much.
 *
 * subscribers.exempt_*         a standing decision: this house is not billed at all (a mosque, a
 *                              relative), with the reason and who made the call. It changes no reading
 *                              and no kWh — the meter still tells the truth about the network, and only
 *                              the money stops.
 * subscribers.discount_percent a permanent subsidy, applied to the charge BEFORE the credit and the
 *                              office's ceiling, so a subsidised house is a normal month at a lower price.
 * bills.discount_amount        the money waived on one invoice: the one-cycle discount (شهر مجاني being
 *                              100% of it) plus the permanent percent in force when the invoice was
 *                              produced. Stored, like credit_applied, so the printed invoice adds up on
 *                              its own terms and the office can show what its generosity cost.
 * bills.discount_note/_category/_by/_at
 *                              the deliberate act: a reason, a category, who granted it, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->timestamp('exempt_at')->nullable();
            $table->string('exempt_reason', 200)->nullable();
            $table->string('exempt_category', 40)->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('discount_note', 200)->nullable();
            $table->string('discount_category', 40)->nullable();
            $table->unsignedBigInteger('discount_by')->nullable();
            $table->timestamp('discount_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->dropColumn(['exempt_at', 'exempt_reason', 'exempt_category', 'discount_percent']);
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn(['discount_amount', 'discount_note', 'discount_category', 'discount_by', 'discount_at']);
        });
    }
};
