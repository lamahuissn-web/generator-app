<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@a.com', 'password' => 'x', 'role' => 'admin']);
    }

    private function meteredSub(float $maintenance = 15, int $ampere = 5): Subscriber
    {
        $route = Route::create(['name' => 'Test Area']);

        return Subscriber::create([
            'route_id' => $route->id,
            'name' => 'Test Subscriber',
            'meter_no' => 'M-1',
            'type' => Subscriber::TYPE_METERED,
            'amperage' => $ampere,
            'maintenance_fee' => $maintenance,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
    }

    private function baseline(Subscriber $sub, string $period, float $value): void
    {
        \App\Models\Reading::create([
            'subscriber_id' => $sub->id,
            'period' => $period,
            'current_reading' => $value,
            'previous_reading' => 0,
            'consumption' => 0,
        ]);
    }

    public function test_metered_reading_builds_bill_from_usage_plus_maintenance(): void
    {
        $sub = $this->meteredSub(15);
        $this->baseline($sub, '2026-06', 1000);

        $result = BillingService::enterMeteredReading($sub, '2026-07', 1040, $this->admin->id);
        $bill = $result['bill'];

        $this->assertSame(40.0, (float) $bill->consumption); // 1040 - 1000
        $this->assertSame(10.0, (float) $bill->usage_amount); // 40 x $0.25
        $this->assertSame(15.0, (float) $bill->maintenance_fee);
        $this->assertSame(25.0, (float) $bill->total);
        $this->assertSame(0.0, (float) $bill->prev_debt);
        $this->assertSame('metered', $bill->type);
    }

    public function test_zero_usage_bills_maintenance_only(): void
    {
        $sub = $this->meteredSub(15);
        $this->baseline($sub, '2026-06', 1000);

        $result = BillingService::enterMeteredReading($sub, '2026-07', 1000, $this->admin->id);

        $this->assertSame(0.0, (float) $result['bill']->consumption);
        $this->assertSame(0.0, (float) $result['bill']->usage_amount);
        $this->assertSame(15.0, (float) $result['bill']->total);
    }

    public function test_lower_reading_is_rejected(): void
    {
        $sub = $this->meteredSub();
        $this->baseline($sub, '2026-06', 1000);

        $this->expectException(\RuntimeException::class);
        BillingService::enterMeteredReading($sub, '2026-07', 900, $this->admin->id);
    }

    public function test_duplicate_reading_for_period_is_rejected(): void
    {
        $sub = $this->meteredSub();
        $this->baseline($sub, '2026-06', 1000);
        BillingService::enterMeteredReading($sub, '2026-07', 1050, $this->admin->id);

        $this->expectException(\RuntimeException::class);
        BillingService::enterMeteredReading($sub, '2026-07', 1060, $this->admin->id);
    }

    public function test_reading_rolls_previous_debt_into_new_bill(): void
    {
        $sub = $this->meteredSub(15);
        $this->baseline($sub, '2026-06', 1000);
        BillingService::enterMeteredReading($sub, '2026-07', 1040, $this->admin->id); // 25 unpaid

        $result = BillingService::enterMeteredReading($sub, '2026-08', 1080, $this->admin->id);

        $this->assertSame(25.0, (float) $result['bill']->prev_debt);
        $this->assertSame(10.0, (float) $result['bill']->usage_amount);
        $this->assertSame(50.0, (float) $sub->outstanding());
    }

    public function test_partial_payment_allocates_fifo_across_bills(): void
    {
        $sub = $this->meteredSub(15);
        $this->baseline($sub, '2026-06', 1000);
        BillingService::enterMeteredReading($sub, '2026-07', 1040, $this->admin->id); // bill 25
        BillingService::enterMeteredReading($sub, '2026-08', 1080, $this->admin->id); // bill 10 + prev 25 = due 50

        $result = BillingService::collect($sub, 30, $this->admin->id, null, '2026-08');

        $this->assertSame(30.0, $result['applied']);
        $this->assertSame(20.0, $result['remaining']);
        $this->assertSame(25.0, (float) $sub->bills()->where('period', '2026-07')->first()->paid_amount);
        $this->assertSame(5.0, (float) $sub->bills()->where('period', '2026-08')->first()->paid_amount);
        $this->assertSame(20.0, $sub->outstanding());
    }

    public function test_overpayment_is_clamped_to_balance(): void
    {
        $sub = $this->meteredSub(15);
        $this->baseline($sub, '2026-06', 1000);
        BillingService::enterMeteredReading($sub, '2026-07', 1040, $this->admin->id); // 25

        $result = BillingService::collect($sub, 1000, $this->admin->id);

        $this->assertSame(25.0, $result['applied']);
        $this->assertSame(975.0, $result['excess']);
        $this->assertSame(0.0, $result['remaining']);
        $this->assertSame(0.0, $sub->outstanding());
    }

    public function test_flat_collect_creates_bill_and_rolls_unpaid_periods(): void
    {
        $route = Route::create(['name' => 'Test Area']);
        $sub = Subscriber::create([
            'route_id' => $route->id,
            'name' => 'Flat Sub',
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        // June bill exists but was never paid.
        BillingService::ensureFlatBill($sub, '2026-06');

        // July payment: ensures July bill (60), allocation clears June first (FIFO).
        $result = BillingService::collect($sub, 60, $this->admin->id, null, '2026-07');

        $june = $sub->bills()->where('period', '2026-06')->first();
        $july = $sub->bills()->where('period', '2026-07')->first();
        $this->assertNotNull($july);
        $this->assertSame(60.0, (float) $july->prev_debt); // rolled-over June debt
        $this->assertSame(60.0, (float) $june->paid_amount);
        $this->assertSame(0.0, (float) $july->paid_amount);
        $this->assertSame(60.0, $sub->outstanding());
    }

    public function test_collect_with_nothing_due_throws(): void
    {
        $sub = $this->meteredSub();
        $this->expectException(\RuntimeException::class);
        BillingService::collect($sub, 10, $this->admin->id);
    }

    public function test_payment_reversal_restores_balance_and_audits(): void
    {
        $sub = $this->meteredSub(15);
        $this->baseline($sub, '2026-06', 1000);
        BillingService::enterMeteredReading($sub, '2026-07', 1040, $this->admin->id); // bill 25
        BillingService::enterMeteredReading($sub, '2026-08', 1080, $this->admin->id); // bill 10, prev 25

        $result = BillingService::collect($sub, 30, $this->admin->id, null, '2026-08');
        $payment = $result['payment'];
        $this->assertSame(20.0, $sub->outstanding());

        BillingService::reversePayment($payment->fresh());

        $this->assertSame(0.0, (float) $sub->bills()->where('period', '2026-07')->first()->paid_amount);
        $this->assertSame(0.0, (float) $sub->bills()->where('period', '2026-08')->first()->paid_amount);
        $this->assertSame(50.0, $sub->outstanding());
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
        $this->assertDatabaseCount('payment_allocations', 0);

        // Every mutation is audited: reading x2, payment created, payment reversed.
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'payment.created')->count());
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'payment.reversed')->count());
        $this->assertSame(2, \App\Models\AuditLog::where('action', 'reading.created')->count());
    }

    public function test_unpaid_reading_can_be_deleted_with_its_bill(): void
    {
        $sub = $this->meteredSub(15);
        $this->baseline($sub, '2026-06', 1000);
        $result = BillingService::enterMeteredReading($sub, '2026-07', 1040, $this->admin->id);
        $this->assertSame(25.0, $sub->outstanding());

        BillingService::deleteReadingAndBill($result['reading']);

        $this->assertDatabaseMissing('readings', ['id' => $result['reading']->id]);
        $this->assertDatabaseMissing('bills', ['id' => $result['bill']->id]);
        $this->assertSame(0.0, $sub->outstanding());
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'reading.deleted')->count());
    }

    public function test_paid_reading_cannot_be_deleted(): void
    {
        $sub = $this->meteredSub(15);
        $this->baseline($sub, '2026-06', 1000);
        $result = BillingService::enterMeteredReading($sub, '2026-07', 1040, $this->admin->id); // 25
        BillingService::collect($sub, 25, $this->admin->id, null, '2026-07');

        $this->expectException(\RuntimeException::class);
        BillingService::deleteReadingAndBill($result['reading']);
    }

    public function test_unpaid_flat_bill_can_be_deleted(): void
    {
        $route = Route::create(['name' => 'Test Area']);
        $sub = Subscriber::create([
            'route_id' => $route->id,
            'name' => 'Flat Sub',
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        $bill = BillingService::ensureFlatBill($sub, '2026-07');
        $this->assertSame(60.0, $sub->outstanding());

        BillingService::deleteFlatBill($bill);

        $this->assertDatabaseMissing('bills', ['id' => $bill->id]);
        $this->assertSame(0.0, $sub->outstanding());
    }
}
