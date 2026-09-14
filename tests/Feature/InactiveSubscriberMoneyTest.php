<?php

namespace Tests\Feature;

use App\Admin\Actions\CollectPayment;
use App\Admin\Actions\RecordReading;
use App\Livewire\Admin\ActionForm;
use App\Livewire\Collector\SubscriberShow;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Principle VI: the status governs SERVICE; a debt is money already owed.
 *
 * A stopped subscriber still owes what he owes — often the balance of a month he was connected
 * for — and the panel used to hide the collection button on both sides, which made the invoice
 * uncollectable while it sat in the receivables and explained nothing to anybody. What the status
 * still governs is the READING (and, by the same rule, a NEW charge), because those are about
 * service going forward.
 *
 * These tests pin BOTH halves: the debt is collectable in the office and in the field, and the
 * reading is still refused in both. A test that only proved the collection works would let the
 * boundary rot.
 */
class InactiveSubscriberMoneyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    /** Stopped, owes $40 — the case the office asked about. */
    private Subscriber $stopped;

    /** Stopped and settled: nothing to collect, so nothing to offer. */
    private Subscriber $stoppedClear;

    /** Running and owing $25: the ordinary case must not change. */
    private Subscriber $running;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');
        Setting::set(Currency::RATE_KEY, '89500');
        app('cache')->flush();

        $this->admin = User::create([
            'name' => 'مدير', 'email' => 'admin@inactive.test', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@inactive.test', 'password' => bcrypt('secret'),
            'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $route = Route::create(['name' => 'Ain El Remmaneh']);

        $this->stopped = $this->subscriber('موقوف وعليه دين', $route, Subscriber::STATUS_INACTIVE);
        $this->bill($this->stopped, '2026-08', 40.0);

        $this->stoppedClear = $this->subscriber('موقوف ومسكّر', $route, Subscriber::STATUS_INACTIVE);

        $this->running = $this->subscriber('نشط وعليه دين', $route, Subscriber::STATUS_ACTIVE);
        $this->bill($this->running, '2026-08', 25.0);
    }

    private function subscriber(string $name, Route $route, string $status): Subscriber
    {
        return Subscriber::create([
            'route_id' => $route->id, 'name' => $name, 'meter_no' => 'M-'.$name,
            'type' => Subscriber::TYPE_METERED, 'maintenance_mode' => Subscriber::MAINT_EXEMPT,
            'status' => $status, 'created_by' => $this->admin->id,
        ]);
    }

    private function bill(Subscriber $subscriber, string $period, float $total): Bill
    {
        return Bill::create([
            'number' => 'INV-'.$period.'-'.$subscriber->id, 'subscriber_id' => $subscriber->id,
            'period' => $period, 'type' => Subscriber::TYPE_METERED, 'total' => $total,
        ]);
    }

    // ---- the rule -----------------------------------------------------------------

    public function test_a_stopped_subscriber_with_a_debt_is_collectable(): void
    {
        $this->assertTrue(app(CollectPayment::class)->visible($this->stopped));
    }

    public function test_a_stopped_subscriber_who_owes_nothing_is_not_offered_a_collection(): void
    {
        $this->assertFalse(app(CollectPayment::class)->visible($this->stoppedClear));
    }

    public function test_a_running_subscriber_is_unchanged(): void
    {
        $this->assertTrue(app(CollectPayment::class)->visible($this->running));
    }

    public function test_the_office_is_told_when_the_money_comes_from_a_stopped_account(): void
    {
        // The warning is the difference between a rule and an accident: the operator sees why a
        // stopped account has a collect button at all.
        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => CollectPayment::class, 'subjectId' => $this->stopped->id])
            ->call('openModal')
            ->assertSee(__('a.collect_inactive_notice'));

        // …and the ordinary case stays quiet.
        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => CollectPayment::class, 'subjectId' => $this->running->id])
            ->call('openModal')
            ->assertDontSee(__('a.collect_inactive_notice'));
    }

    public function test_the_list_row_and_the_file_agree_about_who_is_collectable(): void
    {
        // The row action derives from the action definition rather than restating the rule, so the
        // two can only ever say the same thing. Asserted on the rendered list.
        $collectUrl = fn (Subscriber $s): string => route('admin.subscribers.show', ['subscriber' => $s->id, 'action' => 'collect']);

        $this->actingAs($this->admin)->get('/admin/subscribers')
            ->assertOk()
            ->assertSee($collectUrl($this->stopped), false)
            ->assertDontSee($collectUrl($this->stoppedClear), false);
    }

    // ---- the money moves ----------------------------------------------------------

    public function test_the_office_can_take_an_old_debt_from_a_stopped_account(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => CollectPayment::class, 'subjectId' => $this->stopped->id])
            ->call('openModal')
            ->set('state.amount', 15)
            ->call('submit')
            ->assertHasNoErrors();

        // A normal collection: the receipt exists, the invoice was applied FIFO, the rest remains.
        $this->assertSame(15.0, (float) Payment::where('subscriber_id', $this->stopped->id)->sum('amount'));
        $this->assertSame(25.0, $this->stopped->fresh()->outstanding());
    }

    public function test_the_field_app_takes_an_old_debt_from_a_stopped_account(): void
    {
        Livewire::actingAs($this->collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->stopped])
            ->set('paymentAmount', '40')
            ->call('savePayment')
            ->assertHasNoErrors();

        $this->assertSame(40.0, (float) Payment::where('subscriber_id', $this->stopped->id)->sum('amount'));
        $this->assertSame(0.0, $this->stopped->fresh()->outstanding());
    }

    public function test_the_collector_is_told_the_account_is_stopped(): void
    {
        Livewire::actingAs($this->collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->stopped])
            ->assertSee(__('portal.inactive_service_note'));
    }

    // ---- and the boundary: service still stops with the account -------------------

    public function test_a_reading_is_still_refused_in_the_office(): void
    {
        $this->assertFalse(app(RecordReading::class)->visible($this->stopped));
        $this->assertTrue(app(RecordReading::class)->visible($this->running));
    }

    public function test_a_reading_is_still_refused_in_the_field(): void
    {
        Livewire::actingAs($this->collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->stopped])
            ->set('readingInput', '2000')
            ->call('saveReading')
            ->assertHasErrors('reading');

        $this->assertSame(0, \App\Models\Reading::where('subscriber_id', $this->stopped->id)->count());
    }

    public function test_a_stopped_subscriber_is_still_billed_nothing_and_read_nowhere(): void
    {
        // The other side of the same rule, asserted where the field app decides who is on the
        // round: neither of the stopped subscribers is in it.
        $this->assertSame(0, Subscriber::query()->active()->whereIn('id', [$this->stopped->id, $this->stoppedClear->id])->count());
    }

    // ---- the figure the office decides with -------------------------------------

    public function test_the_dashboard_names_the_debt_that_sits_on_stopped_accounts(): void
    {
        // Total debt is 40 (stopped) + 25 (running) = 65, and the card says which part is the one
        // nobody is chasing.
        $this->actingAs($this->admin)->get('/admin')
            ->assertOk()
            ->assertSee(money(65.0))
            ->assertSee(__('dash.outstanding_sub_inactive', ['amount' => money(40.0)]));
    }
}
