<?php

namespace Tests\Feature;

use App\Admin\Actions\CorrectLockedReading;
use App\Admin\Tables\ReadingsTable;
use App\Livewire\Admin\DataTable;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CycleLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The closed-cycle gate.
 *
 * Until this existed, a reading from any month could be deleted as easily as this morning's: the
 * delete button sat on every row, the only guard was "the bill is unpaid", and the sole trace was an
 * audit line. A subscriber's whole history was one stray click deep.
 *
 * The rule: anything older than the cycle being worked is CLOSED. A closed cycle can still be
 * corrected — mistakes are real and slips arrive late — but only with a written reason from an
 * admin, and the reason is kept beside the figures that were removed.
 */
class ClosedCycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Route $route;

    private Subscriber $subscriber;

    private string $period;

    private string $lastPeriod;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->period = BillingService::currentPeriod();
        $this->lastPeriod = now()->startOfMonth()->subMonth()->format('Y-m');

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@lock.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@lock.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'Ain El Remmaneh']);
        $this->subscriber = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'محمد عيتاني', 'meter_no' => '1123',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);
    }

    private function reading(string $period, float $value, float $consumption): Reading
    {
        return BillingService::enterMeteredReading($this->subscriber, $period, $value, $this->admin->id)['reading'];
    }

    // ---- the rule itself ------------------------------------------------------

    public function test_only_cycles_older_than_the_one_being_worked_are_closed(): void
    {
        $this->assertFalse(CycleLock::isClosed($this->period), 'the cycle being worked is open');
        $this->assertTrue(CycleLock::isClosed($this->lastPeriod));
        $this->assertTrue(CycleLock::isClosed(now()->startOfMonth()->subMonths(11)->format('Y-m')));
        // A cycle ahead of this month is work that has not started, not history.
        $this->assertFalse(CycleLock::isClosed(now()->startOfMonth()->addMonth()->format('Y-m')));
        // Nothing sensible to lock about a value that is not a period at all.
        $this->assertFalse(CycleLock::isClosed(null));
        $this->assertFalse(CycleLock::isClosed('nonsense'));
    }

    public function test_refusal_names_what_is_missing(): void
    {
        // No reason: the message says so.
        $this->assertStringContainsString(
            'سبب مكتوب',
            (string) CycleLock::refusal($this->lastPeriod, null, $this->admin),
        );

        // A collector is told it takes an admin — even with a reason in hand.
        $this->assertStringContainsString(
            'صلاحية أدمن',
            (string) CycleLock::refusal($this->lastPeriod, 'رقم مقلوب', $this->collector),
        );

        // An admin with a reason: nothing to refuse.
        $this->assertNull(CycleLock::refusal($this->lastPeriod, 'رقم مقلوب', $this->admin));

        // An open cycle needs neither.
        $this->assertNull(CycleLock::refusal($this->period, null, $this->collector));
    }

    // ---- deleting -------------------------------------------------------------

    public function test_this_cycle_delete_stays_a_single_click(): void
    {
        $reading = $this->reading($this->period, 1400, 1400);

        Livewire::actingAs($this->admin);

        ReadingsTable::make()->runAction('deleteReading', $reading);

        $this->assertSame(0, Reading::whereKey($reading->id)->count());
    }

    public function test_a_closed_cycle_cannot_be_corrected_without_a_written_reason(): void
    {
        $reading = $this->reading($this->lastPeriod, 1350, 70);

        $this->actingAs($this->admin);

        try {
            BillingService::deleteReadingAndBill($reading);
            $this->fail('A closed-cycle correction without a reason must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('سبب مكتوب', $e->getMessage());
            $this->assertStringContainsString(period_label_ar($this->lastPeriod), $e->getMessage());
        }

        $this->assertSame(1, Reading::whereKey($reading->id)->count(), 'nothing was removed by the refusal');
        $this->assertSame(1, Bill::where('reading_id', $reading->id)->count());
    }

    public function test_a_collector_cannot_correct_a_closed_cycle_even_with_a_reason(): void
    {
        $reading = $this->reading($this->lastPeriod, 1350, 70);

        $this->actingAs($this->collector);

        try {
            BillingService::deleteReadingAndBill($reading, 'بدي امسحها');
            $this->fail('A closed-cycle correction from a collector must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('صلاحية أدمن', $e->getMessage());
        }

        $this->assertSame(1, Reading::whereKey($reading->id)->count());
    }

    public function test_an_admin_with_a_reason_removes_the_reading_and_the_reason_is_kept(): void
    {
        $reading = $this->reading($this->lastPeriod, 1350, 70);
        $billId = $reading->bill->id;

        $this->actingAs($this->admin);

        BillingService::deleteReadingAndBill($reading, 'رقم مقلوب — 1350 بدل 1320');

        $this->assertSame(0, Reading::whereKey($reading->id)->count());
        $this->assertSame(0, Bill::whereKey($billId)->count());

        // The trail says what was removed, why, when it happened and who did it.
        $log = AuditLog::where('action', 'reading.deleted')->latest('id')->first();
        $this->assertSame(1350.0, (float) $log->payload['current_reading']);
        $this->assertSame($this->lastPeriod, $log->payload['period']);
        $this->assertSame('رقم مقلوب — 1350 بدل 1320', $log->payload['reason']);
        $this->assertTrue($log->payload['cycle_closed']);
        $this->assertSame($this->admin->id, $log->actor_id);
    }

    // ---- the screen -----------------------------------------------------------

    public function test_the_list_says_which_rows_are_closed(): void
    {
        $this->reading($this->period, 1400, 1400);
        $this->reading($this->lastPeriod, 1350, 70);

        $this->actingAs($this->admin);

        $component = Livewire::test(DataTable::class, ['table' => ReadingsTable::class]);

        $component->assertSee('حالة الدورة')->assertSee('دورة مقفلة');

        // And the chip is on the closed row, not on the cycle being worked.
        $closed = $component->instance()->rows()->items();
        $byPeriod = collect($closed)->keyBy('period');
        $this->assertTrue(CycleLock::isClosed($byPeriod[$this->lastPeriod]->period));
        $this->assertFalse(CycleLock::isClosed($byPeriod[$this->period]->period));
    }

    public function test_the_row_offers_correction_on_a_closed_cycle_and_one_click_delete_on_an_open_one(): void
    {
        $open = $this->reading($this->period, 1400, 1400);
        $closed = $this->reading($this->lastPeriod, 1350, 70);

        $actions = collect(ReadingsTable::make()->rowActions());
        $delete = $actions->firstWhere('key', 'delete');
        $correct = $actions->firstWhere('key', 'correct');

        // The plain delete is hidden on history, and the correction is hidden on today's work.
        $this->assertTrue(($delete['hidden'])($closed));
        $this->assertFalse(($delete['hidden'])($open));
        $this->assertFalse(($correct['hidden'])($closed));
        $this->assertTrue(($correct['hidden'])($open));
        $this->assertSame(CorrectLockedReading::class, $correct['action_form']);
    }

    public function test_the_correction_modal_requires_a_reason_and_is_only_shown_for_a_closed_reading(): void
    {
        $closed = $this->reading($this->lastPeriod, 1350, 70);
        $open = $this->reading($this->period, 1400, 1400);

        $action = app(CorrectLockedReading::class)->forSubject($closed);

        $this->assertTrue($action->visible($closed));
        $this->assertFalse($action->visible($open));
        $this->assertSame(['required', 'string', 'max:160'], $action->sections()[0]['fields'][0]['rules']);

        // Submitting without a reason is refused before anything is touched.
        $this->actingAs($this->admin);

        Livewire::test(\App\Livewire\Admin\ActionForm::class, [
            'action' => CorrectLockedReading::class,
            'subjectId' => $closed->id,
        ])
            ->call('openModal')
            ->set('state.reason', '')
            ->call('submit')
            ->assertHasErrors('state.reason');

        $this->assertSame(1, Reading::whereKey($closed->id)->count());
    }

    public function test_submitting_the_correction_with_a_reason_removes_the_reading(): void
    {
        $closed = $this->reading($this->lastPeriod, 1350, 70);

        $this->actingAs($this->admin);

        Livewire::test(\App\Livewire\Admin\ActionForm::class, [
            'action' => CorrectLockedReading::class,
            'subjectId' => $closed->id,
            'returnTo' => route('admin.readings.index'),
        ])
            ->call('openModal')
            ->set('state.reason', 'القراءة سُجّلت لمشترك غلط')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(0, Reading::whereKey($closed->id)->count());
        $this->assertSame(
            'القراءة سُجّلت لمشترك غلط',
            AuditLog::where('action', 'reading.deleted')->latest('id')->first()->payload['reason'],
        );
    }

    /**
     * Filling a house that was never read in a past month is not a correction: there is no figure to
     * undo, so the gate must not stand in the way of catching up. (This is the office's late-slip
     * case, and it is why the rule protects what exists rather than the calendar.)
     */
    public function test_a_missing_reading_can_still_be_filled_in_for_a_closed_cycle(): void
    {
        $this->actingAs($this->admin);

        $result = BillingService::enterMeteredReading($this->subscriber, $this->lastPeriod, 1350, $this->admin->id);

        $this->assertSame($this->lastPeriod, $result['reading']->period);
        $this->assertSame(1, Reading::where('period', $this->lastPeriod)->count());
    }
}
