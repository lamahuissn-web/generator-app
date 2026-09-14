<?php

namespace Tests\Feature;

use App\Admin\Actions\CollectPayment;
use App\Livewire\Admin\ActionForm;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\CollectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A receipt carries the day the money was COLLECTED, not only the day it was typed in
 * (specs/RISKS.md §1).
 *
 * A collector with no signal writes his day in at night; the office enters yesterday's receipts this
 * morning. Before this, both landed in today's figures — the month's collection rate was wrong on
 * the first of every month, and a receipt for the 31st was numbered as next month's paper. The date
 * is asked for where the delay actually happens (the office's form) and left empty everywhere else,
 * so recording a collection as it happens is unchanged.
 */
class PaymentBusinessDateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'مدير', 'email' => 'admin@date.test', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $route = Route::create(['name' => 'Ain El Remmaneh']);

        $this->subscriber = Subscriber::create([
            'route_id' => $route->id, 'name' => 'محمد عيتاني', 'meter_no' => 'M-1',
            'type' => Subscriber::TYPE_METERED, 'maintenance_mode' => Subscriber::MAINT_EXEMPT,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);

        Bill::create([
            'number' => 'INV-2026-08-9001', 'subscriber_id' => $this->subscriber->id,
            'period' => '2026-08', 'type' => Subscriber::TYPE_METERED, 'total' => 40.0,
        ]);
    }

    private function collect(string $date): void
    {
        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => CollectPayment::class, 'subjectId' => $this->subscriber->id])
            ->call('openModal')
            ->set('state.amount', 40)
            ->set('state.collected_on', $date)
            ->call('submit')
            ->assertHasNoErrors();
    }

    public function test_a_receipt_dated_last_month_lands_in_that_month(): void
    {
        $this->collect('2026-08-31');

        $payment = Payment::where('subscriber_id', $this->subscriber->id)->firstOrFail();

        $this->assertSame('2026-08-31', $payment->collectedOn()->format('Y-m-d'));

        // The paper belongs to the month the money moved: REC-2026-08-…, not September's series.
        $this->assertStringStartsWith('REC-2026-08-', $payment->number);

        // And the period figures agree: August has it, September does not.
        $this->assertSame(40.0, CollectionService::collectedBetween(
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'),
        ));
        $this->assertSame(0.0, CollectionService::collectedBetween(
            Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'),
        ));
    }

    public function test_a_collection_dated_today_still_works_the_same(): void
    {
        $this->collect(now()->toDateString());

        $payment = Payment::where('subscriber_id', $this->subscriber->id)->firstOrFail();

        $this->assertSame(now()->toDateString(), $payment->collectedOn()->format('Y-m-d'));
        // The bill was settled FIFO, exactly as before.
        $this->assertSame(0.0, $this->subscriber->fresh()->outstanding());
    }

    public function test_a_future_collection_date_is_refused(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => CollectPayment::class, 'subjectId' => $this->subscriber->id])
            ->call('openModal')
            ->set('state.amount', 40)
            ->set('state.collected_on', now()->addDay()->toDateString())
            ->call('submit')
            ->assertHasErrors('state.collected_on');

        $this->assertSame(0, Payment::query()->count(), 'nothing was recorded');
    }

    public function test_a_receipt_without_a_stated_day_is_dated_the_day_it_was_entered(): void
    {
        // The field app says nothing, and that is the normal case: a row written before the column
        // existed, or one recorded as it is collected, must still be counted on its own day.
        $payment = Payment::create([
            'number' => 'REC-2026-09-9002', 'subscriber_id' => $this->subscriber->id,
            'amount' => 10.0, 'method' => Payment::METHOD_CASH_USD,
        ]);

        $this->assertNull($payment->fresh()->collected_on);
        $this->assertSame(
            $payment->fresh()->created_at->format('Y-m-d'),
            $payment->fresh()->collectedOn()->format('Y-m-d'),
        );

        $this->assertSame(10.0, CollectionService::collectedBetween(
            now()->copy()->startOfMonth(), now()->copy()->endOfMonth(),
        ));
    }
}
