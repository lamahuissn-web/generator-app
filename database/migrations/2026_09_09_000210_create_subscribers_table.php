<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('meter_no', 40)->nullable()->unique();
            $table->enum('type', ['metered', 'flat_rate'])->default('metered');
            $table->unsignedSmallInteger('amperage')->nullable();
            $table->decimal('maintenance_fee', 12, 2)->default(0);
            $table->decimal('flat_fee', 12, 2)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['route_id', 'status']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
