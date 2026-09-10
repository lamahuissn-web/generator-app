<?php

use App\Models\Bill;
use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- per-cycle ministry tariff (falls back to the global setting) ----
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7)->unique();          // YYYY-MM
            $table->decimal('kwh_price', 8, 4);
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ---- human-readable document numbers ---------------------------------
        Schema::table('bills', function (Blueprint $table) {
            $table->string('number', 32)->nullable()->unique()->after('id');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('number', 32)->nullable()->unique()->after('id');
        });

        // Backfill: INV-<period>-<subscriber>, REC-<payment month>-<id>.
        foreach (Bill::query()->orderBy('id')->get() as $bill) {
            DB::table('bills')->where('id', $bill->id)->update([
                'number' => 'INV-'.$bill->period.'-'.str_pad((string) $bill->subscriber_id, 4, '0', STR_PAD_LEFT),
            ]);
        }
        foreach (Payment::query()->orderBy('id')->get() as $payment) {
            DB::table('payments')->where('id', $payment->id)->update([
                'number' => 'REC-'.($payment->created_at?->format('Y-m') ?? now()->format('Y-m')).'-'.str_pad((string) $payment->id, 4, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('number');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('number');
        });
        Schema::dropIfExists('tariffs');
    }
};
