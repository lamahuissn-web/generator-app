<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\Settings;
use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The invoice total, and the office's rounding rule.
 *
 * Prices multiply into cents: 105 kWh × $0.25 is $26.25, and $26.25 is change nobody can make at
 * the door. The office's answer — chosen deliberately, and it is a PRICE decision, not a cosmetic
 * one — is to round the total UP to the whole dollar, so any fraction at all becomes one more
 * dollar.
 *
 * The rules these tests pin:
 *   - it applies to NEW subscription invoices only, never to an invoice already issued (a paid
 *     invoice whose total moved would leave paid_amount > total and break every settlement);
 *   - the LINES keep the figures the meter and the tariff produced — an invoice is a record of a
 *     measurement, so the rounding lands on the figure being asked for, not on the arithmetic;
 *   - a special invoice (parts, labour) is never rounded: its total is a sum the office agreed
 *     with the subscriber over the counter;
 *   - a charge of nothing is still nothing — rounding up does not turn 30 cents into an invoice.
 */
class BillRoundingTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08';

    private User $admin;

    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        Setting::set('kwh_price', '0.25');
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_NONE);

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@rounding.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'Ain El Remmaneh']);
    }

    private function subscriber(array $attributes = []): Subscriber
    {
        return Subscriber::create(array_merge([
            'route_id' => $this->route->id, 'name' => 'محمد عيتاني', 'meter_no' => '1123',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ], $attributes));
    }

    /** A metered month: a reading is entered and its invoice produced, exactly as the panel does. */
    private function meteredBill(Subscriber $subscriber, float $consumption, ?float $reading = null): Bill
    {
        BillingService::enterMeteredReading($subscriber, self::PERIOD, $reading ?? $consumption, $this->admin->id);

        return $subscriber->bills()->where('period', self::PERIOD)->firstOrFail();
    }

    // ---- the rule itself ----------------------------------------------------------

    public function test_without_the_setting_an_invoice_is_issued_to_the_cent(): void
    {
        $bill = $this->meteredBill($this->subscriber(), 105);

        $this->assertSame(26.25, (float) $bill->usage_amount, '105 kWh × $0.25');
        $this->assertSame(26.25, (float) $bill->total);
    }

    public function test_the_office_can_round_a_subscription_invoice_up_to_the_dollar(): void
    {
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);

        $bill = $this->meteredBill($this->subscriber(), 105);

        $this->assertSame(27.0, (float) $bill->total, '26.25 → 27');
        // The rounding rides ON the consumption line, so the invoice adds up on its own terms…
        $this->assertSame(27.0, (float) $bill->usage_amount, '26.25 + the 0.75 that rounds it up');
        $this->assertSame(
            (float) $bill->total,
            round((float) $bill->usage_amount + (float) $bill->maintenance_fee, 2),
            'what the lines add up to is what the invoice asks for',
        );
        // …while what was MEASURED does not move: the readings and the consumption are the meter's.
        $this->assertSame(105.0, (float) $bill->consumption);
    }

    public function test_a_charge_that_is_already_whole_is_not_bumped(): void
    {
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);

        // 100 kWh × $0.25 = $25.00 exactly: a round total is left alone, and so is the subscriber.
        $bill = $this->meteredBill($this->subscriber(), 100);

        $this->assertSame(25.0, (float) $bill->total);
        $this->assertSame(25.0, (float) $bill->usage_amount, 'nothing to absorb');
    }

    public function test_the_maintenance_fee_is_part_of_what_is_rounded(): void
    {
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);

        $subscriber = $this->subscriber([
            'maintenance_mode' => Subscriber::MAINT_CUSTOM, 'maintenance_fee' => 15,
        ]);

        // 26.25 of consumption + 15.00 of maintenance = 41.25 → 42.00, not 27.00 — and the fee line
        // is the office's own number, so the consumption line carries the 0.75.
        $bill = $this->meteredBill($subscriber, 105);

        $this->assertSame(42.0, (float) $bill->total);
        $this->assertSame(15.0, (float) $bill->maintenance_fee);
        $this->assertSame(27.0, (float) $bill->usage_amount);
        $this->assertSame(42.0, round((float) $bill->usage_amount + (float) $bill->maintenance_fee, 2));
    }

    public function test_a_flat_rate_invoice_is_rounded_too(): void
    {
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);

        $subscriber = $this->subscriber([
            'type' => Subscriber::TYPE_FLAT, 'meter_no' => null, 'flat_fee' => 26.30,
        ]);

        $bill = BillingService::ensureFlatBill($subscriber, self::PERIOD);

        $this->assertSame(27.0, (float) $bill->total);
        // The agreed fee absorbs it: the one line on a flat invoice IS the total.
        $this->assertSame(27.0, (float) $bill->flat_amount);
        $this->assertSame((float) $bill->total, (float) $bill->flat_amount);
    }

    public function test_a_special_invoice_is_never_rounded(): void
    {
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);

        $subscriber = $this->subscriber();

        // A part and an hour of labour, agreed over the counter: 10.10 + 16.20 = 26.30.
        $bill = BillingService::createSpecialInvoice($subscriber, [
            ['description' => 'قطعة', 'quantity' => 1, 'unit_price' => 10.10],
            ['description' => 'ييد عاملة', 'quantity' => 1, 'unit_price' => 16.20],
        ], 'تصليح', null, self::PERIOD, $this->admin->id)['bill'];

        $this->assertSame(26.30, (float) $bill->total, 'the office agreed this sum, so it stands');
    }

    // ---- where the rule is applied ---------------------------------------------------

    public function test_the_monthly_run_issues_rounded_invoices_too(): void
    {
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);

        $subscriber = $this->subscriber();

        // A reading exists (the meter was read) but no invoice yet: the run's job.
        Reading::create([
            'subscriber_id' => $subscriber->id, 'period' => self::PERIOD,
            'previous_reading' => 0, 'current_reading' => 105, 'consumption' => 105,
            'entered_by' => $this->admin->id,
        ]);

        BillingService::periodRun(self::PERIOD, null, true, $this->admin->id);

        $bill = $subscriber->bills()->where('period', self::PERIOD)->firstOrFail();

        $this->assertSame(27.0, (float) $bill->total, 'the run must not be a second set of rules');
        $this->assertSame(27.0, (float) $bill->usage_amount);
    }

    public function test_nothing_to_bill_stays_nothing(): void
    {
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);

        $subscriber = $this->subscriber();

        // A meter read at zero consumption and no maintenance fee: rounding up must not invent a
        // one-dollar invoice out of a charge of nothing.
        Reading::create([
            'subscriber_id' => $subscriber->id, 'period' => self::PERIOD,
            'previous_reading' => 1200, 'current_reading' => 1200, 'consumption' => 0,
            'entered_by' => $this->admin->id,
        ]);

        BillingService::periodRun(self::PERIOD, null, true, $this->admin->id);

        $this->assertNull($subscriber->bills()->where('period', self::PERIOD)->first());
    }

    // ---- invoices that already exist ---------------------------------------------------

    public function test_the_setting_never_rewrites_an_invoice_already_issued(): void
    {
        $bill = $this->meteredBill($this->subscriber(), 105);
        $this->assertSame(26.25, (float) $bill->total);

        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);

        // Money may already have been taken against this invoice; a total that moved underneath it
        // would leave a paid invoice owing more than it was issued for.
        $this->assertSame(26.25, (float) $bill->fresh()->total);
    }

    // ---- what the customer is handed ----------------------------------------------------

    public function test_the_printed_invoice_adds_up_and_never_names_a_rounding(): void
    {
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);

        $bill = $this->meteredBill($this->subscriber(), 105);

        $paper = $this->actingAs($this->admin)
            ->get(route('print.bill', ['bill' => $bill->id, 'size' => 'a4']))
            ->assertOk();

        $paper->assertSee('$27.00');
        // "105.0 kWh × $0.25" beside $27.00 is a multiplication that no longer holds, so the tariff
        // is not printed on a line that carries a rounding…
        $paper->assertDontSee('kWh ×');
        // …and the paper never explains itself with a word the office did not want on it.
        $paper->assertDontSee('تقريب');
    }

    public function test_an_invoice_with_nothing_to_round_still_shows_its_tariff(): void
    {
        // No rounding in play: the multiplication IS the amount, so it stays on the paper.
        $bill = $this->meteredBill($this->subscriber(), 100);

        $this->actingAs($this->admin)
            ->get(route('print.bill', ['bill' => $bill->id, 'size' => 'a4']))
            ->assertOk()
            ->assertSee('kWh ×');
    }

    public function test_the_office_sets_the_rule_on_the_settings_screen(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Settings::class)
            ->assertSet('state.bill_rounding', BillingService::ROUND_NONE)
            ->set('state.bill_rounding', BillingService::ROUND_CEIL_DOLLAR)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(BillingService::ROUND_CEIL_DOLLAR, Setting::get(BillingService::ROUNDING_KEY));

        // And a value that is not one of the two rules is refused rather than stored.
        Livewire::actingAs($this->admin)
            ->test(Settings::class)
            ->set('state.bill_rounding', 'round_a_little')
            ->call('save')
            ->assertHasErrors('state.bill_rounding');

        $this->assertSame(BillingService::ROUND_CEIL_DOLLAR, Setting::get(BillingService::ROUNDING_KEY));
    }
}
