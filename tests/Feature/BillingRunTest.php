<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingRunTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Route $route;

    private Route $otherRoute;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@a.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);
        $this->route = Route::create(['name' => 'Area A']);
        $this->otherRoute = Route::create(['name' => 'Area B']);
    }

    private function subscriber(array $attrs = []): Subscriber
    {
        return Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'Test Subscriber',
            'type' => Subscriber::TYPE_METERED,
            'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
            ...$attrs,
        ]);
    }

    private function reading(Subscriber $sub, string $period, float $current, float $previous = 0): Reading
    {
        return Reading::create([
            'subscriber_id' => $sub->id,
            'period' => $period,
            'current_reading' => $current,
            'previous_reading' => $previous,
            'consumption' => $current - $previous,
            'entered_by' => $this->admin->id,
        ]);
    }

    /** A metered subscriber with a reading entered for the run period (no bill yet). */
    private function meteredWithReading(string $period = '2026-07'): Subscriber
    {
        $sub = $this->subscriber(['name' => 'Metered Ready', 'meter_no' => 'M-1']);
        $this->reading($sub, $period, 1040, 1000); // 40 kWh -> $10 usage

        return $sub;
    }

    public function test_preview_reports_states_and_writes_nothing(): void
    {
        $flat = $this->subscriber(['name' => 'Flat A', 'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 60, 'maintenance_fee' => 0]);
        $metered = $this->meteredWithReading();
        $noReading = $this->subscriber(['name' => 'No Reading', 'meter_no' => 'M-2', 'maintenance_fee' => 20]);
        $inactive = $this->subscriber(['name' => 'Inactive One', 'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 50, 'status' => Subscriber::STATUS_INACTIVE]);
        $zero = $this->subscriber(['name' => 'Zero Fee', 'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 0, 'maintenance_fee' => 0]);

        $run = BillingService::periodRun('2026-07');
        $byName = collect($run['rows'])->keyBy('name');

        $this->assertSame(BillingService::RUN_CREATE, $byName['Flat A']['state']);
        $this->assertSame(BillingService::RUN_CREATE, $byName['Metered Ready']['state']);
        $this->assertSame(BillingService::RUN_NO_READING, $byName['No Reading']['state']);
        $this->assertSame(BillingService::RUN_INACTIVE, $byName['Inactive One']['state']);
        $this->assertSame(BillingService::RUN_ZERO, $byName['Zero Fee']['state']);

        // Preview totals: flat fee 60 + (40 kWh x $0.25 + $15 maintenance)
        $this->assertSame(60.0, $byName['Flat A']['total']);
        $this->assertSame(25.0, $byName['Metered Ready']['total']);
        $this->assertSame(85.0, $run['invoiced']);
        $this->assertSame(0, $run['created']);

        // Nothing was written.
        $this->assertDatabaseCount('bills', 0);
        $this->assertSame(5, count($run['rows']));
        $this->assertSame(0, AuditLog::where('action', 'bill.generated')->count());
        $this->assertSame(0, $flat->bills()->count());
        $this->assertSame(0, $metered->bills()->count());
        $this->assertSame(0.0, $zero->outstanding());
    }

    public function test_commit_creates_bills_and_rolls_previous_debt(): void
    {
        $flat = $this->subscriber(['name' => 'Flat A', 'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 60, 'maintenance_fee' => 0]);
        $metered = $this->meteredWithReading();

        // An older unpaid bill rolls into the new one.
        BillingService::ensureFlatBill($flat, '2026-06');

        $run = BillingService::periodRun('2026-07', null, true, $this->admin->id);

        $this->assertSame(2, $run['created']);
        $this->assertSame(2, $run['summary'][BillingService::RUN_CREATE]); // the plan it reported before writing

        $flatBill = $flat->bills()->where('period', '2026-07')->first();
        $this->assertNotNull($flatBill);
        $this->assertSame(60.0, (float) $flatBill->total);
        $this->assertSame(60.0, (float) $flatBill->prev_debt);
        $this->assertSame('flat_rate', $flatBill->type);
        $this->assertNull($flatBill->reading_id);

        $meteredBill = $metered->bills()->where('period', '2026-07')->first();
        $this->assertNotNull($meteredBill);
        $this->assertSame(40.0, (float) $meteredBill->consumption);
        $this->assertSame(10.0, (float) $meteredBill->usage_amount);
        $this->assertSame(15.0, (float) $meteredBill->maintenance_fee);
        $this->assertSame(25.0, (float) $meteredBill->total);
        $this->assertNotNull($meteredBill->reading_id);
        $this->assertSame(120.0, $flat->outstanding()); // June 60 + July 60
        $this->assertSame(25.0, $metered->outstanding());

        // Every generated bill is audited as a bulk run.
        $this->assertSame(2, AuditLog::where('action', 'bill.generated')->count());
    }

    public function test_skips_inactive_zero_and_reading_less_subscribers(): void
    {
        $this->meteredWithReading();
        $this->subscriber(['name' => 'No Reading', 'meter_no' => 'M-2', 'maintenance_fee' => 20]);
        $this->subscriber(['name' => 'Inactive One', 'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 50, 'status' => Subscriber::STATUS_INACTIVE]);
        $this->subscriber(['name' => 'Zero Fee', 'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 0, 'maintenance_fee' => 0]);

        $run = BillingService::periodRun('2026-07', null, true, $this->admin->id);

        $this->assertSame(1, $run['created']);
        $this->assertDatabaseCount('bills', 1);
        $this->assertSame(0, Bill::whereHas('subscriber', fn ($q) => $q->where('name', 'No Reading'))->count());
        $this->assertSame(0, Bill::whereHas('subscriber', fn ($q) => $q->where('name', 'Inactive One'))->count());
        $this->assertSame(0, Bill::whereHas('subscriber', fn ($q) => $q->where('name', 'Zero Fee'))->count());
    }

    public function test_running_twice_never_double_bills(): void
    {
        $flat = $this->subscriber(['name' => 'Flat A', 'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 60, 'maintenance_fee' => 0]);
        $this->meteredWithReading();

        BillingService::periodRun('2026-07', null, true, $this->admin->id);
        $second = BillingService::periodRun('2026-07', null, true, $this->admin->id);

        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['summary'][BillingService::RUN_EXISTS]);
        $this->assertDatabaseCount('bills', 2);
        $this->assertSame(60.0, (float) $flat->bills()->where('period', '2026-07')->value('total'));
    }

    public function test_route_filter_limits_the_run(): void
    {
        $routeA = $this->subscriber(['name' => 'On A', 'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 30, 'maintenance_fee' => 0]);
        $onB = Subscriber::create([
            'route_id' => $this->otherRoute->id,
            'name' => 'On B',
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 40,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        $run = BillingService::periodRun('2026-07', $this->route->id, true, $this->admin->id);

        $this->assertSame(1, $run['created']);
        $this->assertSame(1, $routeA->bills()->where('period', '2026-07')->count());
        $this->assertSame(0, $onB->bills()->where('period', '2026-07')->count());
    }

    public function test_invalid_period_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        BillingService::periodRun('2026-13');
    }
}
