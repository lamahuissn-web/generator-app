<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per confirmed attempt from a phone.
     *
     * WHY: the collector's phone retries — a street with no signal, a tunnel, a hand that taps twice —
     * and a retry that is not recognised as the SAME attempt is a second receipt for money the office
     * was handed once. The UNIQUE index is the door: two concurrent requests with one key cannot both
     * insert, so the loser reads the winner's answer instead of writing its own. `response` holds the
     * committed answer exactly as it was sent, because rebuilding it later from the tables would make
     * a replay depend on edits that happened afterwards. The row is written inside the money
     * transaction, so a refusal by the engine takes it back and the key stays usable.
     */
    public function up(): void
    {
        Schema::create('api_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('status', 20)->default('in_progress');
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_operations');
    }
};
