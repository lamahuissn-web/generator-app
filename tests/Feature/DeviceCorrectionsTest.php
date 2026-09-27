<?php

namespace Tests\Feature;

use App\Admin\Actions\CorrectRefuelling;
use App\Admin\Actions\CorrectService;
use App\Admin\Actions\RemoveRefuelling;
use App\Admin\Actions\RemoveService;
use App\Livewire\Admin\ActionForm;
use App\Livewire\Admin\Pages\DeviceFile;
use App\Livewire\Admin\Pages\OperationsCentre;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Equipment;
use App\Models\EquipmentService;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Refuelling;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Reports\OperationsReport;
use App\Services\BillingService;
use App\Services\CycleLock;
use App\Services\EquipmentLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * تصحيح تعبئة أو صيانة — Feature 006.
 *
 * What this file pins, in the owner's own terms: a typo in what the machine drank or what was done to it can
 * be corrected, and the correcting REPLACES the row rather than editing it in place, so the figures the row
 * feeds (المنتج · الفاقد · كلفة الكيلوواط · موعد الصيانة) are read again from the new values and never left
 * half-updated. For a service that means its expense moves WITH it — updated, created, or removed — because
 * the expense is the row the month's costs come from.
 *
 * The month gate is CycleLock's, so the two tests that matter most are the pair: an open month corrects with
 * no ceremony, a closed one refuses until the reason is written down. And the whole thing is admin-only at
 * both ends — the button and the write path.
 */
class DeviceCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Route $route;

    private Equipment $device;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'مدير', 'email' => 'admin@a.com', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);

        $this->collector = User::create([
            'name' => 'جابي', 'email' => 'col@a.com', 'password' => bcrypt('secret'),
            'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'منطقة الفحص']);

        $this->device = Equipment::create([
            'name' => 'المولّد', 'kind' => Equipment::KIND_GENERATOR, 'status' => Equipment::STATUS_WORKING,
            'engine_hours_opening' => 0, 'created_by' => $this->admin->id,
        ]);
    }

    // ---- the world the tests build -------------------------------------------------------------

    /** The month the office is working in: a correction here is housekeeping. */
    private function openMonth(): string
    {
        return BillingService::currentPeriod();
    }

    /** A month CycleLock has closed: anything in it was reported and collected against. */
    private function closedMonth(): string
    {
        return now()->copy()->subMonth()->format('Y-m');
    }

    private function refuel(string $date, array $extra = []): Refuelling
    {
        return Refuelling::create(array_merge([
            'equipment_id' => $this->device->id, 'date' => $date, 'litres' => 100,
            'price_per_litre' => 1.25, 'notes' => null, 'created_by' => $this->admin->id,
            // The add path always writes the source; a fixture that left it null would be a row the app
            // cannot produce.
            'kwh_source' => Refuelling::SOURCE_ESTIMATED,
        ], $extra));
    }

    private function service(string $date, float $cost, array $extra = []): EquipmentService
    {
        return EquipmentService::create(array_merge([
            'equipment_id' => $this->device->id, 'date' => $date, 'kind' => EquipmentService::KIND_OIL,
            'cost' => $cost, 'notes' => null, 'created_by' => $this->admin->id,
        ], $extra));
    }

    /** A service whose money was recorded the way the screen records it: an expense it points at. */
    private function serviceWithExpense(string $date, float $cost): EquipmentService
    {
        $expense = Expense::create([
            'category' => Expense::CAT_OIL, 'description' => 'زيت — المولّد', 'amount' => $cost,
            'expense_date' => $date, 'entered_by' => $this->admin->id,
        ]);

        return $this->service($date, $cost, ['expense_id' => $expense->id]);
    }

    private function metered(string $name = 'بيت بعدّاد'): Subscriber
    {
        return Subscriber::create([
            'route_id' => $this->route->id, 'name' => $name, 'type' => Subscriber::TYPE_METERED,
            'meter_no' => 'M-'.uniqid(), 'amperage' => 10, 'maintenance_fee' => 10,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
    }

    /** Correct through the real modal path: mount, open, set, submit. */
    private function correctThroughTheModal(string $action, int $subjectId, array $state, ?User $as = null): \Livewire\Features\SupportTesting\Testable
    {
        $component = Livewire::actingAs($as ?? $this->admin)
            ->test(ActionForm::class, ['action' => $action, 'subjectId' => $subjectId])
            ->call('openModal');

        foreach ($state as $key => $value) {
            $component->set('state.'.$key, $value);
        }

        return $component->call('submit');
    }

    // ---- FR-1: a correction replaces the row, and the month is read again ----------------------

    public function test_a_refuelling_is_corrected_in_one_save_and_the_months_production_follows(): void
    {
        Setting::set('kwh_per_litre', '2.5');
        $row = $this->refuel($this->openMonth().'-02');

        $this->assertSame(250.0, OperationsReport::forMonth($this->openMonth())->production()['kwh']);

        $this->correctThroughTheModal(CorrectRefuelling::class, $row->id, ['litres' => 140])
            ->assertHasNoErrors();

        // One row stands, with the new figure — not two, and not a half-edited one.
        $this->assertSame(1, Refuelling::count());
        $this->assertSame(140.0, (float) Refuelling::first()->litres);
        $this->assertNotSame($row->id, Refuelling::first()->id, 'a replacement, not an edited row');

        // And the month is re-derived from it, not left on the old figure.
        $this->assertSame(350.0, OperationsReport::forMonth($this->openMonth())->production()['kwh']);

        $audit = AuditLog::where('action', 'refuelling.corrected')->firstOrFail();
        $this->assertSame(100.0, (float) $audit->payload['before']['litres']);
        $this->assertSame(140.0, (float) $audit->payload['after']['litres']);
        $this->assertFalse($audit->payload['cycle_closed']);
    }

    public function test_a_correction_from_estimated_to_measured_relabels_the_row(): void
    {
        Setting::set('kwh_per_litre', '2.5');
        $row = $this->refuel($this->openMonth().'-02');

        $this->assertSame(Refuelling::SOURCE_ESTIMATED, $row->kwh_source, 'no kWh written means estimated');

        $this->correctThroughTheModal(CorrectRefuelling::class, $row->id, ['kwh_produced' => 900])
            ->assertHasNoErrors();

        $fresh = Refuelling::firstOrFail();
        $this->assertSame(Refuelling::SOURCE_MEASURED, $fresh->kwh_source);
        $this->assertSame(900.0, (float) $fresh->kwh_produced);

        $production = OperationsReport::forMonth($this->openMonth())->production();
        $this->assertSame(900.0, $production['kwh'], 'the recorded kWh replaces litres × the rate');
        $this->assertSame(900.0, $production['measured']);
    }

    // ---- FR-2: a service's money moves with it, in the same transaction ------------------------

    public function test_correcting_a_services_cost_moves_its_expense_too(): void
    {
        $service = $this->serviceWithExpense($this->openMonth().'-03', 40.0);
        $expenseId = $service->expense_id;

        $this->assertSame(40.0, (float) Expense::sum('amount'));

        $this->correctThroughTheModal(CorrectService::class, $service->id, ['cost' => 65])
            ->assertHasNoErrors();

        // The money is the SAME expense row, corrected — not a second one beside it.
        $this->assertSame(1, Expense::count(), 'the expense is moved, never duplicated');
        $this->assertSame($expenseId, (int) Expense::firstOrFail()->id);
        $this->assertSame(65.0, (float) Expense::firstOrFail()->amount);

        $fresh = EquipmentService::firstOrFail();
        $this->assertSame(65.0, (float) $fresh->cost);
        $this->assertSame($expenseId, (int) $fresh->expense_id);

        $audit = AuditLog::where('action', 'service.corrected')->firstOrFail();
        $this->assertSame(40.0, (float) $audit->payload['before']['expense_amount']);
        $this->assertSame(65.0, (float) $audit->payload['after']['cost']);
    }

    public function test_correcting_a_services_cost_to_zero_removes_its_expense(): void
    {
        $service = $this->serviceWithExpense($this->openMonth().'-03', 40.0);

        $this->correctThroughTheModal(CorrectService::class, $service->id, ['cost' => 0, 'record_expense' => false])
            ->assertHasNoErrors();

        // A repair that no longer costs anything must stop costing the month money.
        $this->assertSame(0, Expense::count());
        $this->assertNull(EquipmentService::firstOrFail()->expense_id);
        $this->assertSame(0.0, (float) EquipmentService::firstOrFail()->cost);
    }

    public function test_a_service_that_gains_a_cost_gains_an_expense(): void
    {
        $service = $this->service($this->openMonth().'-03', 0.0);

        $this->assertNull($service->expense_id);

        $this->correctThroughTheModal(CorrectService::class, $service->id, ['cost' => 65, 'record_expense' => true])
            ->assertHasNoErrors();

        $this->assertSame(1, Expense::count());
        $this->assertSame(65.0, (float) Expense::firstOrFail()->amount);
        $this->assertSame((int) Expense::firstOrFail()->id, (int) EquipmentService::firstOrFail()->expense_id);
    }

    public function test_removing_a_service_takes_its_expense_with_it(): void
    {
        $service = $this->serviceWithExpense($this->openMonth().'-03', 40.0);

        $this->correctThroughTheModal(RemoveService::class, $service->id, [])
            ->assertHasNoErrors();

        $this->assertSame(0, EquipmentService::count());
        $this->assertSame(0, Expense::count(), 'an orphaned expense is how a deleted repair keeps costing');

        $audit = AuditLog::where('action', 'service.removed')->firstOrFail();
        $this->assertSame(40.0, (float) $audit->payload['removed']['cost']);
        $this->assertTrue($audit->payload['expense_removed']);
    }

    public function test_removing_a_refuelling_audits_the_values_that_went(): void
    {
        $row = $this->refuel($this->openMonth().'-02', ['engine_hours' => 120]);

        $this->correctThroughTheModal(RemoveRefuelling::class, $row->id, [])
            ->assertHasNoErrors();

        $this->assertSame(0, Refuelling::count());

        $audit = AuditLog::where('action', 'refuelling.removed')->firstOrFail();
        $this->assertSame(100.0, (float) $audit->payload['removed']['litres']);
        $this->assertSame(120.0, (float) $audit->payload['removed']['engine_hours']);
    }

    // ---- FR-3: a closed month needs a written reason ------------------------------------------

    public function test_a_closed_months_correction_needs_a_written_reason(): void
    {
        $row = $this->refuel($this->closedMonth().'-10');

        $this->correctThroughTheModal(CorrectRefuelling::class, $row->id, ['litres' => 999])
            ->assertHasErrors('state.reason');

        $this->assertSame(100.0, (float) Refuelling::firstOrFail()->litres, 'nothing moved without a reason');
        $this->assertSame(0, AuditLog::where('action', 'refuelling.corrected')->count());

        $this->correctThroughTheModal(CorrectRefuelling::class, $row->id, ['litres' => 140, 'reason' => 'رقم مقلوب'])
            ->assertHasNoErrors();

        $this->assertSame(140.0, (float) Refuelling::firstOrFail()->litres);

        $audit = AuditLog::where('action', 'refuelling.corrected')->firstOrFail();
        $this->assertSame('رقم مقلوب', $audit->payload['reason']);
        $this->assertTrue($audit->payload['cycle_closed'], 'the trail says the month was closed');
    }

    public function test_the_write_site_refuses_a_closed_month_even_with_no_form_above_it(): void
    {
        $row = $this->refuel($this->closedMonth().'-10');
        $this->actingAs($this->admin);

        // The service is the invariant: a caller that forgets the form's rule still gets the refusal.
        try {
            EquipmentLog::correctRefuelling($row, [
                'date' => $row->date->toDateString(), 'litres' => 999, 'price_per_litre' => 1.25,
            ], null);
            $this->fail('a closed month accepted a correction with no reason');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                __('refuse.closed_cycle_reason', ['period' => period_label_i18n($this->closedMonth())]),
                $e->getMessage()
            );
        }

        $this->assertSame(100.0, (float) Refuelling::firstOrFail()->litres);
    }

    // ---- FR-4: admin only, at both ends -------------------------------------------------------

    public function test_a_non_admin_sees_no_buttons_and_is_refused_at_the_write_site(): void
    {
        $row = $this->refuel($this->openMonth().'-02');

        Livewire::actingAs($this->collector)->test(DeviceFile::class, ['equipmentId' => $this->device->id])
            ->assertDontSee(__('a.correct_refuel'))
            ->assertDontSee(__('a.del_refuel'));

        Livewire::actingAs($this->admin)->test(DeviceFile::class, ['equipmentId' => $this->device->id])
            ->assertSee(__('a.correct_refuel'))
            ->assertSee(__('a.del_refuel'));

        $this->actingAs($this->collector);

        try {
            EquipmentLog::removeRefuelling($row, null);
            $this->fail('a collector removed a refuelling');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('refuse.admin_only'), $e->getMessage());
        }

        $this->assertSame(1, Refuelling::count());
    }

    // ---- the pair: what must still be allowed ------------------------------------------------

    public function test_a_closed_months_row_offers_the_correction_and_not_the_delete(): void
    {
        $closedOnly = Equipment::create([
            'name' => 'مولّد ثانٍ', 'kind' => Equipment::KIND_GENERATOR, 'status' => Equipment::STATUS_WORKING,
            'engine_hours_opening' => 0, 'created_by' => $this->admin->id,
        ]);

        Refuelling::create([
            'equipment_id' => $closedOnly->id, 'date' => $this->closedMonth().'-10', 'litres' => 100,
            'price_per_litre' => 1.25, 'created_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)->test(DeviceFile::class, ['equipmentId' => $closedOnly->id])
            ->assertSee(__('a.correct_refuel'))
            ->assertDontSee(__('a.del_refuel'))
            ->assertSee(__('ops.timeline_locked'));

        // And the open month's row keeps both, so the fix did not over-refuse.
        $open = $this->refuel($this->openMonth().'-02');

        $this->assertTrue((new RemoveRefuelling)->visible($open));
        $this->assertTrue((new CorrectRefuelling)->visible($open));
        $this->assertFalse((new RemoveRefuelling)->visible(
            Refuelling::where('equipment_id', $closedOnly->id)->firstOrFail()
        ));
    }

    // ---- the board and the money are untouched ------------------------------------------------

    public function test_the_board_moves_no_money_and_the_invoice_still_adds_up(): void
    {
        Setting::set('kwh_per_litre', '2.5');
        $subscriber = $this->metered();
        $period = $this->openMonth();

        BillingService::enterMeteredReading($subscriber, $period, 120, $this->admin->id);
        $bill = Bill::where('subscriber_id', $subscriber->id)->where('period', $period)->firstOrFail();
        BillingService::collect($subscriber->fresh(), 20.0, null, 'دفعة');

        $row = $this->refuel($period.'-02');
        $this->serviceWithExpense($period.'-03', 40.0);

        $before = [
            'bills' => Bill::count(),
            'bills_total' => (float) Bill::sum('total'),
            'payments' => Payment::count(),
            'payments_amount' => (float) Payment::sum('amount'),
            'credit' => (float) Subscriber::sum('credit'),
        ];

        // Rendering both screens, then correcting both log rows through the modal.
        Livewire::actingAs($this->admin)->test(OperationsCentre::class)->call('toggleMore');
        Livewire::actingAs($this->admin)->test(DeviceFile::class, ['equipmentId' => $this->device->id]);

        $this->correctThroughTheModal(CorrectRefuelling::class, $row->id, ['litres' => 160])
            ->assertHasNoErrors();

        Livewire::actingAs($this->admin)->test(OperationsCentre::class)->call('toggleMore');
        Livewire::actingAs($this->admin)->test(DeviceFile::class, ['equipmentId' => $this->device->id]);

        $this->assertSame($before, [
            'bills' => Bill::count(),
            'bills_total' => (float) Bill::sum('total'),
            'payments' => Payment::count(),
            'payments_amount' => (float) Payment::sum('amount'),
            'credit' => (float) Subscriber::sum('credit'),
        ], 'the device log is not a money document');

        $bill->refresh();
        $this->assertSame(round($bill->charges(), 2), (float) $bill->total, 'charges() == total still holds');
    }
}
