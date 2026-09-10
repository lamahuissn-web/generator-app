<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('handover_date');
            $table->string('note', 200)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['collector_id', 'handover_date']);
            $table->index('handover_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_handovers');
    }
};
