<?php

namespace Tests\Feature;

use App\Livewire\Admin\ActionForm;
use App\Livewire\Admin\Pages\DeviceFile;
use App\Livewire\Admin\Pages\OperationsCentre;
use App\Models\Equipment;
use App\Models\EquipmentService;
use App\Models\Expense;
use App\Models\Reading;
use App\Models\Refuelling;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Reports\OperationsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two screens of مركز العمليات — the board and one device's own page.
 *
 * The engine is already tested (OperationsCentreTest). What this file pins is the SURFACE:
 * the board obeys قواعد البساطة (six cards, engineering behind one toggle, no fabricated
 * number), أجهزتي exists, and the two entry modals («تعبئة مازوت» / «صيانة») actually write
 * through the ActionForm modal path.
 */
class OperationsSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private const MONTH = '2026-09';

    private User $admin;

    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeAtTheSeptemberCycle();
        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'a@a.com', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'منطقة الفحص']);
    }

    private function metered(string $name = 'بيت بعدّاد'): Subscriber
    {
        return Subscriber::create([
            'route_id' => $this->route->id, 'name' => $name, 'type' => Subscriber::TYPE_METERED,
            'meter_no' => 'M-'.uniqid(), 'amperage' => 10, 'maintenance_fee' => 10,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
    }

    private function device(array $extra = []): Equipment
    {
        return Equipment::create(array_merge([
            'name' => 'المولّد', 'kind' => Equipment::KIND_GENERATOR, 'status' => Equipment::STATUS_WORKING,
            'engine_hours_opening' => 0, 'created_by' => $this->admin->id,
        ], $extra));
    }

    // ---- the board -------------------------------------------------------------

    public function test_board_renders_with_no_refuelling_and_shows_reasons_not_numbers(): void
    {
        $sub = $this->metered();
        Reading::create([
            'subscriber_id' => $sub->id, 'period' => self::MONTH,
            'current_reading' => 120, 'previous_reading' => 0, 'consumption' => 120,
        ]);

        $board = OperationsReport::forMonth(self::MONTH)->board();
        $this->assertNull($board['energy']['produced']);
        $this->assertSame('no_production', $board['energy']['loss_reason']);

        // The Livewire page renders and the six-card board shows its money; the energy
        // behind the toggle shows the REASON instead of a number.
        Livewire::actingAs($this->admin)->test(OperationsCentre::class)
            ->assertOk()
            ->assertSee(__('ops.income'))
            ->assertSee(__('ops.earned'))
            ->assertSee(__('ops.held'))
            ->assertSee(__('ops.overdue'))
            ->assertSee(__('ops.expenses'))
            ->assertSee(__('ops.net'))
            ->call('toggleMore')
            ->assertSee(__('ops.no_production'))
            // Feature 004 disclosures beside the net
            ->assertSee(__('ops.discounts'))
            ->assertSee(__('ops.exempt_value'));

        // Via HTTP as well (the nav and the route are wired).
        $this->actingAs($this->admin)->get(route('admin.operations-centre'))->assertOk();
    }

    public function test_board_shows_produced_figure_and_its_estimate_label_after_a_refuelling(): void
    {
        Refuelling::create([
            'date' => self::MONTH.'-10', 'litres' => 100, 'price_per_litre' => 1.2,
            'kwh_source' => Refuelling::SOURCE_ESTIMATED,
            'created_by' => $this->admin->id,
        ]);

        // 100 L × default 3.0
        $board = OperationsReport::forMonth(self::MONTH)->board();
        $this->assertSame(300.0, $board['energy']['produced']);
        $this->assertSame('estimated', $board['energy']['production_basis']);

        Livewire::actingAs($this->admin)->test(OperationsCentre::class)
            ->call('toggleMore')
            ->assertSee('300')
            ->assertSee(__('ops.basis_estimated'));
    }

    public function test_board_more_toggle_reveals_engineering_cards(): void
    {
        $c = Livewire::actingAs($this->admin)->test(OperationsCentre::class);
        $c->assertSee(__('ops.more'));
        $c->call('toggleMore')->assertSee(__('ops.fuel_title'))->assertSee(__('ops.produced'));
        $c->call('toggleMore')->assertSee(__('ops.more'));
    }

    // ---- أجهزتي — the resource and one device's own page -----------------------

    public function test_equipment_resource_and_device_file_are_reachable(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin)->get(route('admin.equipment.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.device-file', $device))->assertOk();

        // Sidebar entries exist and the equipment table shows the device.
        $navLabels = collect(\App\Admin\AdminNav::groups())->flatMap(fn ($g) => $g['items'])->pluck('label')->all();
        $this->assertContains(__('nav.ops_centre'), $navLabels);
        $this->assertContains(__('nav.my_equipment'), $navLabels);

        Livewire::actingAs($this->admin)->test(DeviceFile::class, ['equipmentId' => $device->id])
            ->assertOk()
            ->assertSee($device->name)
            ->assertSee(__('ops.timeline_title'))
            ->assertSee(__('eq.refuel'))
            ->assertSee(__('eq.service_action'));
    }

    public function test_device_timeline_mixes_refuellings_and_services(): void
    {
        $device = $this->device();

        $rf = Refuelling::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-10', 'litres' => 40,
            'price_per_litre' => 1, 'kwh_source' => Refuelling::SOURCE_ESTIMATED, 'created_by' => $this->admin->id,
        ]);

        $svc = EquipmentService::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-12', 'kind' => EquipmentService::KIND_OIL,
            'engine_hours' => 100, 'cost' => 15, 'created_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)->test(DeviceFile::class, ['equipmentId' => $device->id])
            ->assertSee(__('ops.timeline_refuel', ['litres' => '40', 'price' => '$1.000']))
            ->assertSee($svc->kindLabel());
    }

    public function test_refuelling_modal_writes_through_action_form(): void
    {
        $device = $this->device();
        $before = Refuelling::count();

        Livewire::actingAs($this->admin)->test(ActionForm::class, [
            'action' => \App\Admin\Actions\RecordRefuelling::class,
            'subjectId' => $device->id,
        ])
            ->call('openModal')
            ->set('state.date', self::MONTH.'-15')
            ->set('state.litres', 55)
            ->set('state.price_per_litre', 0.90)
            ->set('state.engine_hours', null)
            ->set('state.kwh_produced', null)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame($before + 1, Refuelling::count());
        $row = Refuelling::latest('id')->first();
        $this->assertSame(55.0, (float) $row->litres);
        $this->assertSame((float) 0.9, (float) $row->price_per_litre);
        $this->assertSame($device->id, $row->equipment_id);
    }

    public function test_refuelling_modal_prefills_last_price(): void
    {
        Refuelling::create([
            'date' => self::MONTH.'-01', 'litres' => 10, 'price_per_litre' => 1.33,
            'kwh_source' => Refuelling::SOURCE_ESTIMATED, 'created_by' => $this->admin->id,
        ]);

        $device = $this->device();

        $c = Livewire::actingAs($this->admin)->test(ActionForm::class, [
            'action' => \App\Admin\Actions\RecordRefuelling::class,
            'subjectId' => $device->id,
        ]);
        $c->call('openModal');
        $this->assertSame('1.33', (string) $c->get('state.price_per_litre'));
    }

    public function test_service_modal_writes_through_action_form(): void
    {
        $device = $this->device();
        $before = EquipmentService::count();

        Livewire::actingAs($this->admin)->test(ActionForm::class, [
            'action' => \App\Admin\Actions\RecordService::class,
            'subjectId' => $device->id,
        ])
            ->call('openModal')
            ->set('state.date', self::MONTH.'-18')
            ->set('state.kind', EquipmentService::KIND_FILTER)
            ->set('state.engine_hours', 180)
            ->set('state.cost', 25)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame($before + 1, EquipmentService::count());
        $row = EquipmentService::latest('id')->first();
        $this->assertSame(EquipmentService::KIND_FILTER, $row->kind);
        $this->assertSame(25.0, (float) $row->cost);

        // And the money went to the office's own books, once: a service with a cost writes the expense and
        // links to it, so nothing has to be entered a second time and nothing is counted twice.
        $this->assertNotNull($row->expense_id);
        $expense = Expense::find($row->expense_id);
        $this->assertSame(25.0, (float) $expense->amount);
        $this->assertSame(Expense::CAT_OIL, $expense->category, 'a filter belongs to oil');
        $this->assertSame(self::MONTH.'-18', $expense->expense_date->toDateString());
        $this->assertSame(25.0, (float) Expense::query()->sum('amount'), 'one expense, not two');
    }

    // ---- one device needs no list ---------------------------------------------

    public function test_one_device_redirect_goes_to_its_file(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin)
            ->get(route('admin.equipment.redirect'))
            ->assertRedirect(route('admin.device-file', $device));
    }

    public function test_two_devices_redirect_stays_on_the_list(): void
    {
        $this->device(['name' => 'المولّد أ']);
        $this->device(['name' => 'المولّد ب']);

        $this->actingAs($this->admin)
            ->get(route('admin.equipment.redirect'))
            ->assertRedirect(route('admin.equipment.index'));
    }

    public function test_equipment_create_is_reachable(): void
    {
        $this->actingAs($this->admin)->get(route('admin.equipment.create'))->assertOk();
    }

    public function test_a_service_can_leave_the_expense_to_the_office(): void
    {
        $device = $this->device();

        Livewire::actingAs($this->admin)->test(ActionForm::class, [
            'action' => \App\Admin\Actions\RecordService::class,
            'subjectId' => $device->id,
        ])
            ->call('openModal')
            ->set('state.date', self::MONTH.'-19')
            ->set('state.kind', EquipmentService::KIND_REPAIR)
            ->set('state.cost', 30)
            ->set('state.record_expense', false)
            ->call('submit')
            ->assertHasNoErrors();

        $row = EquipmentService::latest('id')->first();
        $this->assertSame(30.0, (float) $row->cost, 'the service is on record');
        $this->assertNull($row->expense_id, 'the office said it already booked this repair');
        $this->assertSame(0.0, (float) Expense::query()->sum('amount'), 'and nothing was counted twice');
    }

    public function test_the_first_refuelling_opens_on_the_offices_default_price(): void
    {
        \App\Models\Setting::set('diesel_price_default', '1.30');
        $device = $this->device();

        Livewire::actingAs($this->admin)->test(ActionForm::class, [
            'action' => \App\Admin\Actions\RecordRefuelling::class,
            'subjectId' => $device->id,
        ])
            ->call('openModal')
            ->assertSet('state.price_per_litre', '1.30')
            ->set('state.litres', '100')
            ->call('submit')
            ->assertHasNoErrors();

        $row = Refuelling::latest('id')->first();
        $this->assertSame(1.3, (float) $row->price_per_litre);
        $this->assertSame(Refuelling::SOURCE_ESTIMATED, $row->kwh_source);
        $this->assertNull($row->kwh_produced, 'zero is not a measurement: nothing is claimed');
    }

    public function test_a_machine_with_a_history_is_not_deleted(): void
    {
        $device = $this->device();
        Refuelling::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-05', 'litres' => 50,
            'price_per_litre' => 1, 'created_by' => $this->admin->id,
        ]);

        $table = new \App\Admin\Tables\EquipmentTable();

        $this->assertFalse($table->canDelete($device->fresh()), 'a machine with a file keeps it');

        $this->expectException(\RuntimeException::class);
        $table->deleteRecord($device->fresh());
    }

    public function test_a_machine_with_no_logs_can_still_be_removed(): void
    {
        $device = $this->device();
        $table = new \App\Admin\Tables\EquipmentTable();

        $this->assertTrue($table->canDelete($device));
        $table->deleteRecord($device);

        $this->assertSame(0, Equipment::count(), 'a device entered by mistake is not a permanent record');
    }

    public function test_the_settings_refuse_a_zero_factor_and_a_meaningless_band(): void
    {
        $component = Livewire::actingAs($this->admin)->test(\App\Livewire\Admin\Pages\Settings::class);

        $component->set('state.flat_draw_factor', '0')->call('save')
            ->assertHasErrors('state.flat_draw_factor');

        $component->set('state.flat_draw_factor', '40')
            ->set('state.maintenance_alert_hours', 'abc')
            ->call('save')
            ->assertHasErrors('state.maintenance_alert_hours');

        $component->set('state.maintenance_alert_hours', '250,500')
            ->set('state.loss_band_normal', '15-5')
            ->call('save')
            ->assertHasErrors('state.loss_band_normal');

        $component->set('state.loss_band_normal', '5-15')->call('save')->assertHasNoErrors();
        $this->assertSame('5-15', \App\Models\Setting::get('loss_band_normal'));
    }

    public function test_the_delete_refusal_says_why_and_the_button_is_hidden(): void
    {
        $device = $this->device();
        Refuelling::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-05', 'litres' => 50,
            'price_per_litre' => 1, 'created_by' => $this->admin->id,
        ]);

        $table = new \App\Admin\Tables\EquipmentTable();

        $this->assertSame(
            __('eq.delete_has_logs', ['name' => $device->name]),
            $table->deleteRefusal($device->fresh()),
            'the office is told which rule it hit, not «this record cannot be deleted»',
        );
    }

    // ---- the third audit's doors, on the surface the office reads -------------------------------------------

    public function test_the_area_card_carries_the_same_consumption_as_the_card_above_it(): void
    {
        $sub = $this->metered('بيت تحوّل');
        Reading::create([
            'subscriber_id' => $sub->id, 'period' => self::MONTH,
            'current_reading' => 120, 'previous_reading' => 0, 'consumption' => 120,
        ]);
        $sub->forceFill(['type' => Subscriber::TYPE_FLAT, 'flat_fee' => 60])->save();
        Refuelling::create([
            'date' => self::MONTH.'-10', 'litres' => 100, 'price_per_litre' => 1,
            'kwh_produced' => 300, 'kwh_source' => Refuelling::SOURCE_MEASURED,
            'created_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)->test(OperationsCentre::class)
            ->call('toggleMore')
            ->assertSee('120.0')
            ->assertDontSee('168.0', 'the area was counting the house twice (120 read + 48 flat draw)');
    }

    public function test_the_generosity_card_names_the_reading_that_is_missing(): void
    {
        $mosque = $this->metered('مسجد');
        $mosque->forceFill(['exempt_at' => now(), 'exempt_reason' => 'مسجد'])->save();

        Livewire::actingAs($this->admin)->test(OperationsCentre::class)
            ->call('toggleMore')
            ->assertSee(__('ops.generosity_unread'))
            ->assertDontSee(__('ops.no_generosity'), 'an exempt house exists: the month did not waive nothing');
    }

    // ---- the fourth audit's doors, on the surface the office reads -------------------------------------

    public function test_the_generosity_card_states_the_zero_instead_of_denying_the_waiver(): void
    {
        // The mosque WAS read; the meter had not moved. The month still waived its maintenance fee, and the
        // disclosure line says so on the same screen.
        $mosque = $this->metered('مسجد');
        $mosque->forceFill(['exempt_at' => now(), 'exempt_reason' => 'مسجد'])->save();
        Reading::create([
            'subscriber_id' => $mosque->id, 'period' => self::MONTH,
            'current_reading' => 0, 'previous_reading' => 0, 'consumption' => 0,
        ]);
        Refuelling::create([
            'date' => self::MONTH.'-10', 'litres' => 100, 'price_per_litre' => 1,
            'kwh_produced' => 300, 'kwh_source' => Refuelling::SOURCE_MEASURED,
            'created_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)->test(OperationsCentre::class)
            ->call('toggleMore')
            ->assertSee('$10.00', 'the fee the month waived is on the same screen')
            ->assertDontSee(__('ops.no_generosity'), 'an exempt house exists: the month did not waive nothing')
            ->assertDontSee(__('ops.generosity_unread'), 'it was read: nothing is missing')
            ->assertDontSee(__('ops.generosity_unknown'));
    }

    public function test_the_area_card_keeps_a_reading_whose_area_is_gone(): void
    {
        $sub = $this->metered('بيت');
        Reading::create([
            'subscriber_id' => $sub->id, 'period' => self::MONTH,
            'current_reading' => 120, 'previous_reading' => 0, 'consumption' => 120,
        ]);
        Refuelling::create([
            'date' => self::MONTH.'-10', 'litres' => 100, 'price_per_litre' => 1,
            'kwh_produced' => 300, 'kwh_source' => Refuelling::SOURCE_MEASURED,
            'created_by' => $this->admin->id,
        ]);

        // A row from before the guard, or an area removed outside the panel.
        $this->route->delete();

        Livewire::actingAs($this->admin)->test(OperationsCentre::class)
            ->call('toggleMore')
            ->assertSee(__('ops.no_area'), 'the area card names the houses no area claims')
            ->assertSee('120.0', 'the network card and the area card still state the same kWh')
            ->assertDontSee(__('t.no_results'), 'the areas card is not empty while the network card counts 120');
    }

    public function test_an_area_that_still_holds_a_subscriber_is_not_deleted(): void
    {
        $this->metered('بيت');

        $table = new \App\Admin\Tables\RoutesTable();

        $this->assertFalse($table->canDelete($this->route), 'the area holds a subscriber');
        $this->assertSame(
            __('a.del_route_guard'),
            $table->deleteRefusal($this->route),
            'the refusal names the way out instead of looking broken',
        );

        $this->expectException(\RuntimeException::class);
        $table->deleteRecord($this->route);
    }

    public function test_an_area_with_no_subscribers_is_still_removed(): void
    {
        $empty = Route::create(['name' => 'منطقة فارغة']);
        $table = new \App\Admin\Tables\RoutesTable();

        $this->assertTrue($table->canDelete($empty));
        $table->deleteRecord($empty);

        $this->assertSame(0, Route::query()->where('name', 'منطقة فارغة')->count(), 'an area entered by mistake is not permanent');
    }
}
