<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM
            $table->enum('type', ['metered', 'flat_rate']);
            $table->foreignId('reading_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->decimal('consumption', 12, 2)->nullable();
            $table->decimal('kwh_price', 8, 4)->nullable();
            $table->decimal('usage_amount', 12, 2)->default(0);
            $table->decimal('maintenance_fee', 12, 2)->default(0);
            $table->decimal('flat_amount', 12, 2)->default(0);
            $table->decimal('prev_debt', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['subscriber_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
