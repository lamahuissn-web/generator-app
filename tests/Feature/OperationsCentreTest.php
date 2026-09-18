<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentService;
use App\Models\Expense;
use App\Models\Reading;
use App\Models\Refuelling;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Reports\OperationsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مركز العمليات والأجهزة الهندسية — the numbers it is allowed to say, and the ones it must refuse to.
 *
 * Two rules from the spec are what these tests really hold: the owner's routine is litres and price (everything
 * else is derived), and a figure with no input is HIDDEN rather than guessed.
 */
class OperationsCentreTest extends TestCase
{
    use RefreshDatabase;

    private const MONTH = '2026-09';

    private User $admin;
    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'a@a.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'ar',
        ]);
        $this->route = Route::create(['name' => 'منطقة الفحص']);
        Setting::flush();
    }

    private function metered(string $name = 'بيت بعدّاد'): Subscriber
    {
        return Subscriber::create([
            'route_id' => $this->route->id, 'name' => $name, 'type' => Subscriber::TYPE_METERED,
            'meter_no' => 'M-'.uniqid(), 'amperage' => 15, 'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
    }

    private function flat(string $name = 'بيت مقطوع'): Subscriber
    {
        return Subscriber::create([
            'route_id' => $this->route->id, 'name' => $name, 'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60, 'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
    }

    private function reading(Subscriber $sub, float $consumption): Reading
    {
        return Reading::create([
            'subscriber_id' => $sub->id, 'period' => self::MONTH,
            'current_reading' => $consumption, 'previous_reading' => 0, 'consumption' => $consumption,
        ]);
    }

    private function refuel(float $litres, array $extra = []): Refuelling
    {
        return Refuelling::create(array_merge([
            'date' => self::MONTH.'-10', 'litres' => $litres, 'price_per_litre' => 1.00,
            'created_by' => $this->admin->id,
        ], $extra));
    }

    private function device(array $extra = []): Equipment
    {
        return Equipment::create(array_merge([
            'name' => 'المولّد', 'kind' => Equipment::KIND_GENERATOR, 'status' => Equipment::STATUS_WORKING,
            'engine_hours_opening' => 0, 'created_by' => $this->admin->id,
        ], $extra));
    }

    // ---- production: litres are enough, and the label says which it was -----------------------

    public function test_litres_alone_give_a_production_figure_marked_estimated(): void
    {
        $this->refuel(100);

        $production = OperationsReport::forMonth(self::MONTH)->production();

        $this->assertSame(300.0, $production['kwh'], '100 litres × the default rate of 3.0 kWh/litre');
        $this->assertSame('estimated', $production['basis']);
        $this->assertTrue($production['rate_is_default'], 'the office must be told it is still the default rate');
    }

    public function test_a_measured_figure_wins_over_the_rate_without_silently_averaging(): void
    {
        $this->refuel(100);
        $this->refuel(50, ['date' => self::MONTH.'-20', 'kwh_produced' => 500, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        $production = OperationsReport::forMonth(self::MONTH)->production();

        // 500 measured + (the 100 litres whose kWh nobody wrote down) × 3.0
        $this->assertSame(800.0, $production['kwh']);
        $this->assertSame(500.0, $production['measured']);
        $this->assertSame(300.0, $production['from_litres']);
        $this->assertSame('mixed', $production['basis']);
    }

    public function test_the_offices_own_rate_replaces_the_default(): void
    {
        Setting::set('kwh_per_litre', '2.5');
        $this->refuel(100);

        $report = OperationsReport::forMonth(self::MONTH);

        $this->assertSame(250.0, $report->production()['kwh']);
        $this->assertFalse($report->rateIsDefault());
    }

    // ---- nothing to show means nothing is shown ----------------------------------------------

    public function test_with_no_refuelling_the_energy_cards_hide_instead_of_guessing(): void
    {
        $this->reading($this->metered(), 120);

        $report = OperationsReport::forMonth(self::MONTH);
        $board = $report->board();

        $this->assertNull($report->production()['kwh'], 'no litres, no claimed production');
        $this->assertNull($board['energy']['produced']);
        $this->assertNull($board['energy']['loss_kwh']);
        $this->assertSame('no_production', $board['energy']['loss_reason']);
        $this->assertNull($report->costPerKwh(), 'cost per kWh needs production');
        $this->assertSame(120.0, $board['energy']['consumed'], 'the readings still say what was consumed');
    }

    // ---- consumption, and the honesty around it ----------------------------------------------

    public function test_consumption_is_the_meters_plus_the_flat_houses_estimate(): void
    {
        $this->reading($this->metered('أ'), 100);
        $this->reading($this->metered('ب'), 200);
        $this->flat('ج');

        $consumption = OperationsReport::forMonth(self::MONTH)->consumption();

        $this->assertSame(300.0, $consumption['metered'], 'the meters');
        $this->assertSame(150.0, $consumption['average_metered']);
        $this->assertSame(60.0, $consumption['flat_estimated'], '150 average × 40% for the one flat house');
        $this->assertSame(360.0, $consumption['kwh']);
        $this->assertTrue($consumption['complete']);
    }

    public function test_unread_meters_are_counted_and_declared(): void
    {
        $this->reading($this->metered('أ'), 100);
        $this->metered('ب — بلا قراءة');

        $consumption = OperationsReport::forMonth(self::MONTH)->consumption();

        $this->assertSame(1, $consumption['unread_meters']);
        $this->assertFalse($consumption['complete'], 'a month with unread meters is not complete');

        $this->refuel(100, ['kwh_produced' => 500, 'kwh_source' => Refuelling::SOURCE_MEASURED]);
        $losses = OperationsReport::forMonth(self::MONTH)->losses();

        $this->assertSame(1, $losses['unread_meters'], 'the loss figure travels with its caveat');
        $this->assertSame(400.0, $losses['kwh'], '500 produced − 100 consumed');
        $this->assertSame(80.0, $losses['percent']);
    }

    // ---- money: diesel from one source, never twice ------------------------------------------

    public function test_diesel_money_comes_from_the_log_or_the_expense_but_never_both(): void
    {
        Expense::create([
            'category' => Expense::CAT_FUEL, 'description' => 'مازوت', 'amount' => 100,
            'quantity' => 100, 'expense_date' => self::MONTH.'-05', 'entered_by' => $this->admin->id,
        ]);
        Expense::create([
            'category' => Expense::CAT_REPAIR, 'description' => 'إصلاح', 'amount' => 40,
            'expense_date' => self::MONTH.'-06', 'entered_by' => $this->admin->id,
        ]);

        $withoutLog = OperationsReport::forMonth(self::MONTH);
        $this->assertSame(100.0, $withoutLog->fuel()['money']);
        $this->assertSame('expenses', $withoutLog->fuel()['basis']);
        $this->assertSame(140.0, $withoutLog->operatingCosts()['total']);

        // Now the office also keeps a refuelling sheet: the log becomes the diesel record and the fuel expense
        // is not added on top of it.
        $this->refuel(80, ['price_per_litre' => 1.25]);

        $withLog = OperationsReport::forMonth(self::MONTH);
        $this->assertSame(100.0, $withLog->fuel()['money'], '80 L × 1.25 from the log');
        $this->assertSame('log', $withLog->fuel()['basis']);
        $this->assertSame(140.0, $withLog->operatingCosts()['total'], 'diesel 100 + repair 40 — the fuel expense is not counted twice');
    }

    public function test_cost_per_kwh_and_what_the_generosity_cost(): void
    {
        Setting::set('kwh_per_litre', '3');
        $exempt = $this->metered('مسجد');
        $exempt->forceFill(['exempt_at' => now(), 'exempt_reason' => 'مسجد'])->save();
        $this->reading($exempt, 50);

        $this->refuel(100, ['price_per_litre' => 1.00]);

        $report = OperationsReport::forMonth(self::MONTH);

        $this->assertSame(0.333, $report->costPerKwh(), '100 diesel ÷ 300 kWh produced');

        $generosity = $report->generosity();
        $this->assertSame(50.0, $generosity['kwh'], 'the exempt house consumed 50 kWh');
        $this->assertSame(16.65, $generosity['cost'], '50 kWh × the 0.333 the card shows');
    }

    public function test_the_generosity_is_beside_the_net_not_inside_it(): void
    {
        $exempt = $this->metered('مسجد');
        $exempt->forceFill(['exempt_at' => now(), 'exempt_reason' => 'مسجد'])->save();
        $this->reading($exempt, 200);
        $this->refuel(100);

        $board = OperationsReport::forMonth(self::MONTH)->board();

        $this->assertNotSame(0.0, $board['cost']['generosity']['kwh']);
        $this->assertArrayNotHasKey('generosity', $board['money'], 'the money cards carry no generosity figure');
        $this->assertSame($board['money']['earned'] - $board['money']['expenses'], $board['money']['net']);
    }

    // ---- the machine's own file ---------------------------------------------------------------

    public function test_next_service_speaks_in_hours(): void
    {
        $device = $this->device();
        EquipmentService::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-01', 'kind' => EquipmentService::KIND_OIL,
            'engine_hours' => 240, 'cost' => 20, 'created_by' => $this->admin->id,
        ]);
        Refuelling::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-10', 'litres' => 10,
            'price_per_litre' => 1, 'engine_hours' => 200, 'created_by' => $this->admin->id,
        ]);
        Refuelling::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-15', 'litres' => 50,
            'price_per_litre' => 1, 'engine_hours' => 245, 'created_by' => $this->admin->id,
        ]);

        $next = OperationsReport::forMonth(self::MONTH)->nextService($device->fresh());

        $this->assertSame(245.0, $next['current'], 'the clock now');
        $this->assertSame(250.0, $next['threshold'], 'the next band above the 240-hour service');
        $this->assertSame(5.0, $next['hours_left']);
        $this->assertSame(__('ops.service_in', ['hours' => '5']), $next['wording']);
        $this->assertNotNull($next['date'], 'the log carries a pace, so a date can be projected');
    }

    public function test_a_service_that_is_past_its_hours_says_so(): void
    {
        $device = $this->device(['engine_hours_opening' => 0]);
        EquipmentService::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-01', 'kind' => EquipmentService::KIND_OIL,
            'engine_hours' => 240, 'created_by' => $this->admin->id,
        ]);
        Refuelling::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-20', 'litres' => 40, 'price_per_litre' => 1,
            'engine_hours' => 265, 'created_by' => $this->admin->id,
        ]);

        $next = OperationsReport::forMonth(self::MONTH)->nextService($device->fresh());

        $this->assertSame(__('ops.service_late', ['hours' => '15']), $next['wording']);
    }

    public function test_a_machine_that_starts_drinking_more_per_hour_is_named(): void
    {
        $device = $this->device();

        // Three refuellings on a steady 1 L/hour, then one that burns 1.6.
        foreach ([[self::MONTH.'-01', 10, 10], [self::MONTH.'-05', 10, 20], [self::MONTH.'-09', 10, 30], [self::MONTH.'-13', 16, 40]] as [$date, $litres, $hours]) {
            Refuelling::create([
                'equipment_id' => $device->id, 'date' => $date, 'litres' => $litres, 'price_per_litre' => 1,
                'engine_hours' => $hours, 'created_by' => $this->admin->id,
            ]);
        }

        $anomaly = OperationsReport::forMonth(self::MONTH)->fuelAnomaly($device->fresh());

        $this->assertNotNull($anomaly);
        $this->assertSame(1.6, $anomaly['litres_per_hour']);
        $this->assertSame(1.0, $anomaly['history']);
        $this->assertSame(60.0, $anomaly['deviation']);
        $this->assertSame('up', $anomaly['direction']);
    }

    public function test_a_steady_machine_is_not_accused(): void
    {
        $device = $this->device();

        foreach ([[self::MONTH.'-01', 10, 10], [self::MONTH.'-05', 10, 20], [self::MONTH.'-09', 10, 30], [self::MONTH.'-13', 10, 40]] as [$date, $litres, $hours]) {
            Refuelling::create([
                'equipment_id' => $device->id, 'date' => $date, 'litres' => $litres, 'price_per_litre' => 1,
                'engine_hours' => $hours, 'created_by' => $this->admin->id,
            ]);
        }

        $this->assertNull(OperationsReport::forMonth(self::MONTH)->fuelAnomaly($device->fresh()));
    }

    public function test_a_device_with_no_logs_has_no_figures(): void
    {
        $device = $this->device();

        $next = OperationsReport::forMonth(self::MONTH)->nextService($device);
        $anomaly = OperationsReport::forMonth(self::MONTH)->fuelAnomaly($device);

        $this->assertNull($next['current']);
        $this->assertNull($next['hours_left']);
        $this->assertNull($next['date'], 'no log, no pace, no invented date');
        $this->assertNull($anomaly);
    }
}
