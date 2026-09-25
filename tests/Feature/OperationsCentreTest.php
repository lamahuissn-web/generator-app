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

    public function test_the_balance_is_also_kept_per_route_and_a_route_never_vanishes(): void
    {
        $this->reading($this->metered('بعدّاد أ'), 100);
        $this->flat('مقطوع ب');

        $other = Route::create(['name' => 'منطقة المقطوعين فقط']);
        Subscriber::create([
            'route_id' => $other->id, 'name' => 'مقطوع ج', 'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60, 'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);

        $rows = OperationsReport::forMonth(self::MONTH)->perRoute()->keyBy('route');

        $this->assertCount(2, $rows, 'a route whose houses are all flat still appears — that is where a leak hides');
        $this->assertSame(100.0, $rows['منطقة الفحص']['metered']);
        $this->assertSame(40.0, $rows['منطقة الفحص']['flat'], 'the one metered reading (100) × 40% for its flat house');
        $this->assertSame(140.0, $rows['منطقة الفحص']['consumed']);
        $this->assertSame(0.0, $rows['منطقة المقطوعين فقط']['metered']);
        $this->assertSame(40.0, $rows['منطقة المقطوعين فقط']['consumed']);
    }

    public function test_a_service_with_a_cost_records_its_expense_once(): void
    {
        $device = $this->device();

        $service = EquipmentService::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-05', 'kind' => EquipmentService::KIND_OIL,
            'engine_hours' => 250, 'cost' => 40, 'created_by' => $this->admin->id,
        ]);

        $this->assertSame(40.0, (float) $service->cost);
        $this->assertSame(0.0, (float) Expense::query()->sum('amount'), 'a service row on its own moves no money');
    }

    // ---- what the centre refuses to say (the audit's blocker: a loss printed from a month nobody read) ----

    public function test_a_month_with_no_readings_shows_no_loss_at_all(): void
    {
        $this->metered('بعدّاد — بلا قراءة');
        $this->flat('مقطوع');
        $this->refuel(100, ['kwh_produced' => 500, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        $report = OperationsReport::forMonth(self::MONTH);
        $losses = $report->losses();

        $this->assertFalse($report->consumption()['known'], 'nothing has been read: consumption is unknown, not zero');
        $this->assertNull($losses['kwh'], 'a loss needs a consumption that means something');
        $this->assertNull($losses['percent']);
        $this->assertSame('no_readings', $losses['reason']);
        $this->assertNull($report->board()['energy']['loss_percent']);
    }

    public function test_the_loss_says_how_much_of_it_rests_on_an_estimate(): void
    {
        $this->reading($this->metered('أ'), 100);
        $this->reading($this->metered('ب'), 200);
        $this->flat('ج');
        $this->refuel(100, ['kwh_produced' => 500, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        $losses = OperationsReport::forMonth(self::MONTH)->losses();

        // consumed = 300 metered + (150 average × 40%) = 360, produced 500 → 140 lost.
        $this->assertSame(140.0, $losses['kwh']);
        $this->assertSame(28.0, $losses['percent']);
        $this->assertSame(16.7, $losses['estimated_share'], '60 of the 360 kWh consumed is an estimate');
    }

    public function test_a_negative_loss_is_reported_but_never_classified(): void
    {
        Setting::set('loss_band_normal', '5-15');
        $sub = $this->metered('مستهلك كبير');
        $this->reading($sub, 900);
        $this->refuel(100, ['kwh_produced' => 500, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        $losses = OperationsReport::forMonth(self::MONTH)->losses();

        $this->assertSame(-400.0, $losses['kwh']);
        $this->assertNull($losses['verdict'], 'consumption above production is a measurement question, not a band');
        $this->assertSame(__('ops.loss_negative'), $losses['note']);
    }

    public function test_a_loss_inside_the_offices_own_band_is_named_normal(): void
    {
        Setting::set('loss_band_normal', '5-15');
        $sub = $this->metered('مشترك');
        $this->reading($sub, 900);
        $this->refuel(100, ['kwh_produced' => 1000, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        $losses = OperationsReport::forMonth(self::MONTH)->losses();

        $this->assertSame(10.0, $losses['percent']);
        $this->assertSame('normal', $losses['verdict'], 'a key, for the screen to translate');
    }

    // ---- the settings the centre needs have somewhere to be typed -------------------------------------

    public function test_the_office_can_set_the_rate_the_factor_and_the_bands(): void
    {
        \Livewire\Livewire::actingAs($this->admin)
            ->test(\App\Livewire\Admin\Pages\Settings::class)
            ->set('state.kwh_per_litre', '2.5')
            ->set('state.flat_draw_factor', '50')
            ->set('state.loss_band_normal', '5-15')
            ->set('state.maintenance_alert_hours', '100,200')
            ->set('state.diesel_price_default', '1.30')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2.5', Setting::get('kwh_per_litre'));
        $this->assertSame('50', Setting::get('flat_draw_factor'));
        $this->assertSame('1.30', Setting::get('diesel_price_default'));

        // And the centre obeys them immediately: 100 litres × 2.5, a flat house at 50% of the average.
        $this->reading($this->metered('أ'), 100);
        $this->flat('ب');
        $this->refuel(100);

        $report = OperationsReport::forMonth(self::MONTH);
        $this->assertSame(250.0, $report->production()['kwh']);
        $this->assertFalse($report->rateIsDefault());
        $this->assertSame(50.0, $report->consumption()['flat_estimated'], '100 average × 50%');
    }

    public function test_a_zero_rate_is_refused(): void
    {
        \Livewire\Livewire::actingAs($this->admin)
            ->test(\App\Livewire\Admin\Pages\Settings::class)
            ->set('state.kwh_per_litre', '0')
            ->call('save')
            ->assertHasErrors('state.kwh_per_litre');
    }

    // ---- the second audit's door: production with no readings at all, whoever the network holds ----------

    public function test_a_fresh_install_shows_no_loss_even_though_diesel_was_bought(): void
    {
        // The spec's own order: the machine and its diesel come before any subscriber exists.
        $this->device();
        $this->refuel(100, ['kwh_produced' => 300, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        $losses = OperationsReport::forMonth(self::MONTH)->losses();

        $this->assertSame(300.0, OperationsReport::forMonth(self::MONTH)->production()['kwh']);
        $this->assertNull($losses['kwh'], 'nobody has been read and nobody exists: a 100% loss is arithmetic, not a measurement');
        $this->assertNull($losses['percent']);
        $this->assertSame('no_readings', $losses['reason']);
    }

    public function test_a_network_of_stopped_houses_shows_no_loss_either(): void
    {
        $this->metered('متوقف أ')->forceFill(['status' => Subscriber::STATUS_INACTIVE])->save();
        $this->flat('متوقف ب')->forceFill(['status' => Subscriber::STATUS_INACTIVE])->save();
        $this->refuel(100, ['kwh_produced' => 300, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        $losses = OperationsReport::forMonth(self::MONTH)->losses();

        $this->assertSame('no_readings', $losses['reason'], 'no reading has been taken — the loss is unknown, not 100%');
        $this->assertNull($losses['kwh']);
    }

    public function test_a_house_read_then_converted_to_flat_is_not_counted_twice(): void
    {
        $sub = $this->metered('بيت تحوّل');
        $this->reading($sub, 120);

        // The office converts him to a flat subscriber in the same month he was read.
        $sub->forceFill(['type' => Subscriber::TYPE_FLAT, 'flat_fee' => 60])->save();

        $consumption = OperationsReport::forMonth(self::MONTH)->consumption();

        $this->assertSame(120.0, $consumption['metered']);
        $this->assertSame(0, $consumption['flat_houses'], 'he is already in the metered figure');
        $this->assertSame(120.0, $consumption['kwh'], 'the month is not inflated by counting him twice');
    }

    public function test_a_route_whose_meters_are_not_read_yet_still_appears(): void
    {
        $this->metered('بلا قراءة');

        $rows = OperationsReport::forMonth(self::MONTH)->perRoute();

        $this->assertCount(1, $rows, 'an area that vanishes reads as "no such area", not "not read yet"');
        $this->assertSame('منطقة الفحص', $rows[0]['route']);
        $this->assertTrue($rows[0]['known']);
    }

    public function test_a_flat_house_with_no_basis_reports_unknown_not_zero(): void
    {
        // Flat only: there is no metered average to anchor their draw.
        $exempt = $this->flat('مسجد');
        $exempt->forceFill(['exempt_at' => now(), 'exempt_reason' => 'مسجد'])->save();
        $this->refuel(100, ['price_per_litre' => 1]);

        $report = OperationsReport::forMonth(self::MONTH);

        $this->assertNull($report->generosity()['kwh'], 'unknown is not zero');
        $this->assertNull($report->consumption()['flat_estimated']);
        $this->assertFalse($report->consumption()['known']);
    }

    // ---- the third audit's doors: the same house read twice per area, and a zero waiving nothing -------------

    public function test_a_house_read_then_converted_to_flat_is_not_counted_again_on_its_route(): void
    {
        $sub = $this->metered('بيت تحوّل');
        $this->reading($sub, 120);

        // The office converts him to flat in the same month he was read.
        $sub->forceFill(['type' => Subscriber::TYPE_FLAT, 'flat_fee' => 60])->save();

        $report = OperationsReport::forMonth(self::MONTH);
        $rows = $report->perRoute()->keyBy('route');

        $this->assertSame(120.0, $report->consumption()['kwh']);
        $this->assertSame(0, $rows['منطقة الفحص']['flats'], 'he is already in the metered figure');
        $this->assertSame(
            120.0,
            $rows['منطقة الفحص']['consumed'],
            'the card above the area says 120 — the area must not say 168 (120 read + 40% for a house already counted)',
        );
        $this->assertSame(
            $report->consumption()['kwh'],
            round((float) $rows->sum('consumed'), 1),
            'the areas add up to the network card: that is what makes it a per-route balance',
        );
    }

    public function test_an_exempt_house_nobody_read_yet_is_not_a_zero(): void
    {
        // An exempt metered house with no reading this month: 0 would be the absence of a reading, not a fact.
        $mosque = $this->metered('مسجد');
        $mosque->forceFill(['exempt_at' => now(), 'exempt_reason' => 'مسجد'])->save();
        $this->refuel(100, ['price_per_litre' => 1]);

        $generosity = OperationsReport::forMonth(self::MONTH)->generosity();

        $this->assertNull($generosity['kwh'], 'unknown is not zero — the money card discloses a waiver beside it');
        $this->assertSame('unread', $generosity['reason'], 'the card names the reading that is missing');
    }

    public function test_a_read_exempt_house_still_costs_what_it_consumed(): void
    {
        $mosque = $this->metered('مسجد');
        $this->reading($mosque, 80);
        $mosque->forceFill(['exempt_at' => now(), 'exempt_reason' => 'مسجد'])->save();
        $this->refuel(100, ['kwh_produced' => 300, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        $generosity = OperationsReport::forMonth(self::MONTH)->generosity();

        $this->assertSame(80.0, $generosity['kwh'], 'a house that WAS read is a figure, not an unknown');
        $this->assertNull($generosity['reason']);
    }

    // ---- the fourth audit's doors: an area nobody owns, a lost tenth, a zero that denied a waiver ------

    public function test_a_house_whose_area_is_gone_is_still_on_the_areas_card(): void
    {
        $sub = $this->metered('بيت بلا منطقة');
        $this->reading($sub, 120);
        $this->refuel(100, ['kwh_produced' => 300, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        // The area is deleted (outside the panel, or a row written before the guard): the houses keep their
        // readings, so the network card goes on counting them.
        $this->route->delete();

        $report = OperationsReport::forMonth(self::MONTH);
        $rows = $report->perRoute()->keyBy('route');

        $this->assertSame(120.0, $report->consumption()['kwh'], 'the reading is still the network measurement');
        $this->assertArrayHasKey(__('ops.no_area'), $rows->all(), 'the areas card names the houses no area claims');
        $this->assertSame(120.0, $rows[__('ops.no_area')]['consumed'], 'the area card must not lose the kWh');
        $this->assertSame(
            $report->consumption()['kwh'],
            round((float) $report->perRoute()->sum('consumed'), 1),
            'the areas add up to the card above them even when no area is left to name',
        );
    }

    public function test_the_areas_add_up_to_the_network_card_to_the_last_tenth(): void
    {
        // Rounding every area separately and the network once are two figures a tenth apart:
        // 35% of an 111.0 average, one flat house in each of two areas.
        Setting::set('flat_draw_factor', '35');
        $this->reading($this->metered('مقروء'), 111);

        foreach (['منطقة المقطوعين أ', 'منطقة المقطوعين ب'] as $name) {
            Subscriber::create([
                'route_id' => Route::create(['name' => $name])->id, 'name' => 'مقطوع '.$name,
                'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 60,
                'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
            ]);
        }

        $report = OperationsReport::forMonth(self::MONTH);
        $rows = $report->perRoute()->keyBy('route');

        $this->assertSame(111.0, $rows['منطقة الفحص']['consumed']);
        $this->assertSame(38.9, $rows['منطقة المقطوعين أ']['consumed'], '35% of the 111.0 average');
        $this->assertSame(38.9, $rows['منطقة المقطوعين ب']['consumed']);
        $this->assertSame(188.8, $report->consumption()['kwh'], 'the card above states the sum of the areas');
        $this->assertSame(
            $report->consumption()['kwh'],
            round((float) $report->perRoute()->sum('consumed'), 1),
            'a tenth may not go missing between the two cards',
        );
    }

    public function test_an_exempt_house_read_at_zero_is_a_figure_and_not_a_denial(): void
    {
        $mosque = $this->metered('مسجد');
        $mosque->forceFill(['exempt_at' => now(), 'exempt_reason' => 'مسجد'])->save();
        $this->reading($mosque, 0); // read this month, and the meter had not moved
        $this->refuel(100, ['kwh_produced' => 300, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        $generosity = OperationsReport::forMonth(self::MONTH)->generosity();

        $this->assertSame(0.0, $generosity['kwh'], 'the house WAS read: the zero is a fact, not an absence');
        $this->assertNull($generosity['reason']);
        $this->assertSame(1, $generosity['exempt_houses'], 'a waiver was granted, and the card has to know it');
    }

    public function test_the_office_can_write_the_band_with_the_dash_its_keyboard_produces(): void
    {
        \Livewire\Livewire::actingAs($this->admin)
            ->test(\App\Livewire\Admin\Pages\Settings::class)
            ->set('state.loss_band_normal', '5–15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('5–15', Setting::get('loss_band_normal'), 'the band is stored as the office typed it');

        $this->reading($this->metered('مشترك'), 900);
        $this->refuel(100, ['kwh_produced' => 1000, 'kwh_source' => Refuelling::SOURCE_MEASURED]);

        $this->assertSame(
            'normal',
            OperationsReport::forMonth(self::MONTH)->losses()['verdict'],
            'an en-dash band classifies like any other, instead of silently never matching',
        );
    }
}
