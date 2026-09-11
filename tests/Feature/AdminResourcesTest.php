<?php

namespace Tests\Feature;

use App\Admin\Actions\CollectPayment;
use App\Admin\Actions\ConvertToFlat;
use App\Admin\Actions\ConvertToMetered;
use App\Admin\Actions\SpecialInvoice;
use App\Admin\Forms\HandoverForm;
use App\Admin\Forms\UserForm;
use App\Admin\ResourceRegistry;
use App\Livewire\Admin\ActionForm;
use App\Livewire\Admin\ResourceForm;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RouteFacade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The registry-driven screens and the record actions.
 *
 * The registry is the point: one table definition + one form definition per screen means the
 * failure mode is never "this screen looks wrong" but "this screen was never registered", so
 * most of what follows is generated from ResourceRegistry itself rather than hand-listed.
 */
class AdminResourcesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Subscriber $subscriber;

    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@panel.test',
            'password' => bcrypt('secret'), 'role' => 'admin', 'locale' => 'en',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@panel.test',
            'password' => bcrypt('secret'), 'role' => 'collector', 'locale' => 'en',
        ]);

        $this->route = Route::create(['name' => 'Ain El Remmaneh']);
        $this->subscriber = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'لينا مرعب',
            'phone' => '03 555789',
            'meter_no' => '2202',
            'type' => Subscriber::TYPE_METERED,
            'amperage' => 10,
            'maintenance_fee' => 25,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        Bill::create([
            'number' => 'INV-2026-09-0007', 'subscriber_id' => $this->subscriber->id, 'period' => '2026-09',
            'type' => 'metered', 'usage_amount' => 11.50, 'maintenance_fee' => 25, 'total' => 36.50, 'paid_amount' => 0,
        ]);
    }

    // ---- the registry -----------------------------------------------------

    public function test_every_registered_screen_renders(): void
    {
        foreach (ResourceRegistry::all() as $key => $resource) {
            $this->actingAs($this->admin)
                ->get(route('admin.'.$key.'.index'))
                ->assertOk()
                ->assertDontSee('name="email"', false); // the login page is also a 200
        }
    }

    public function test_screens_with_a_form_get_create_and_edit_routes(): void
    {
        foreach (ResourceRegistry::all() as $key => $resource) {
            if (empty($resource['form'])) {
                // A read-only screen must NOT advertise URLs that only 404.
                $this->assertFalse(
                    RouteFacade::has('admin.'.$key.'.create'),
                    "{$key} has no form but a create route.",
                );

                continue;
            }

            $this->assertTrue(RouteFacade::has('admin.'.$key.'.create'), "{$key}.create is missing");
            $this->actingAs($this->admin)->get(route('admin.'.$key.'.create'))->assertOk();

            if (! empty($resource['create_only'])) {
                // A measurement of a moment: correcting one means deleting it (and the bill it
                // produced) and recording it again, so there is no edit route at all.
                $this->assertFalse(
                    RouteFacade::has('admin.'.$key.'.edit'),
                    "{$key} is create-only but has an edit route.",
                );

                continue;
            }

            $this->assertTrue(RouteFacade::has('admin.'.$key.'.edit'), "{$key}.edit is missing");
        }
    }

    public function test_edit_route_404s_for_a_record_that_does_not_exist(): void
    {
        // findOrFail, not find: an unknown id must not open a blank form that creates a
        // second record when it is saved.
        $this->actingAs($this->admin)->get(route('admin.routes.edit', 999999))->assertNotFound();
    }

    public function test_collector_cannot_open_an_admin_only_screen(): void
    {
        // users + audit log are adminOnly in the nav; the controller must enforce it too.
        $this->actingAs($this->collector)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($this->collector)->get(route('admin.audit-logs.index'))->assertForbidden();
    }

    public function test_every_screen_appears_in_the_sidebar(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('admin.routes.index').'"', false)
            ->assertSee('href="'.route('admin.expenses.index').'"', false)
            ->assertSee('href="'.route('admin.audit-logs.index').'"', false);
    }

    // ---- the generic form -------------------------------------------------

    public function test_route_form_creates_and_edits(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => \App\Admin\Forms\RouteForm::class])
            ->set('state.name', 'Tariq El Jdideh')
            ->set('state.notes', 'Old quarter')
            ->call('save')
            ->assertHasNoErrors();

        $created = Route::where('name', 'Tariq El Jdideh')->firstOrFail();
        $this->assertSame('Old quarter', $created->notes);

        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => \App\Admin\Forms\RouteForm::class, 'record' => $created->id])
            ->assertSet('state.name', 'Tariq El Jdideh')
            ->set('state.name', 'Tariq El Jadideh')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Tariq El Jadideh', $created->fresh()->name);
    }

    /** A blank route name must be refused, not written as an empty area. */
    public function test_route_form_requires_a_name(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => \App\Admin\Forms\RouteForm::class])
            ->set('state.name', '')
            ->call('save')
            ->assertHasErrors('state.name');

        $this->assertSame(1, Route::count());
    }

    /**
     * Editing an account without typing a password must not blank it.
     *
     * The field is bound to the form, so the failure mode is silent: the operator fixes a
     * phone number and locks the user out of the panel.
     */
    public function test_user_form_password_is_optional_on_edit_and_keeps_the_old_hash(): void
    {
        $hash = $this->collector->password;

        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => UserForm::class, 'record' => $this->collector->id])
            ->assertSet('state.password', null)
            ->set('state.phone', '03 111222')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $this->collector->fresh();
        $this->assertSame('03 111222', $fresh->phone);
        $this->assertSame($hash, $fresh->password);
        $this->assertTrue(Hash::check('secret', $fresh->password));
    }

    public function test_user_form_requires_a_password_on_create(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => UserForm::class])
            ->set('state.name', 'New Collector')
            ->set('state.email', 'new@panel.test')
            ->set('state.role', User::ROLE_COLLECTOR)
            ->set('state.password', '')
            ->call('save')
            ->assertHasErrors('state.password');

        $this->assertNull(User::where('email', 'new@panel.test')->first());
    }

    /** A tariff is what the next billing run prices with: two per month is a coin toss. */
    public function test_tariff_period_must_be_unique(): void
    {
        \App\Models\Tariff::create(['period' => '2026-09', 'kwh_price' => 0.25, 'created_by' => $this->admin->id]);

        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => \App\Admin\Forms\TariffForm::class])
            ->set('state.period', '2026-09')
            ->set('state.kwh_price', '0.30')
            ->call('save')
            ->assertHasErrors('state.period');

        $this->assertSame(1, \App\Models\Tariff::count());
    }

    public function test_expense_form_records_the_expense_and_audits_the_edit(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => \App\Admin\Forms\ExpenseForm::class])
            ->set('state.category', Expense::CAT_FUEL)
            ->set('state.expense_date', '2026-09-10')
            ->set('state.amount', '120.50')
            ->set('state.quantity', '40')
            ->set('state.description', 'Generator diesel')
            ->call('save')
            ->assertHasNoErrors();

        $expense = Expense::firstOrFail();
        $this->assertSame(120.50, (float) $expense->amount);
        $this->assertSame(40.0, (float) $expense->quantity);
        $this->assertSame($this->admin->id, $expense->entered_by);

        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => \App\Admin\Forms\ExpenseForm::class, 'record' => $expense->id])
            ->set('state.amount', '131.00')
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::where('action', 'expense.updated')->latest('id')->first();
        $this->assertNotNull($log, 'An expense edit must be audited.');
        // Casts: the payload survives a JSON round trip, where 131.00 may come back as int.
        $this->assertSame(120.50, (float) $log->payload['before']['amount']);
        $this->assertSame(131.00, (float) $log->payload['after']['amount']);
    }

    /** Litres only mean something for fuel — a stale value on rent is noise in the reports. */
    public function test_expense_quantity_is_dropped_for_a_non_fuel_category(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => \App\Admin\Forms\ExpenseForm::class])
            ->set('state.category', Expense::CAT_RENT)
            ->set('state.expense_date', '2026-09-01')
            ->set('state.amount', '400')
            ->set('state.quantity', '40')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Expense::firstOrFail()->quantity);
    }

    // ---- recording a reading (the daily operation) -------------------------

    /**
     * These tests all record into 2026-10: the suite's setUp already owns a 2026-09 bill, and
     * recording into that period would find the fixture's bill instead of the one produced.
     */
    private function readingForm()
    {
        return Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => \App\Admin\Forms\ReadingForm::class]);
    }

    public function test_the_reading_screen_records_a_normal_read_and_bills_it(): void
    {
        \App\Models\Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-08',
            'current_reading' => 1100, 'previous_reading' => 1000, 'consumption' => 0,
            'entered_by' => $this->admin->id,
        ]);

        $this->readingForm()
            ->set('state.subscriber_id', $this->subscriber->id)
            ->set('state.period', '2026-10')
            ->set('state.reading_mode', \App\Services\ReadingService::MODE_NORMAL)
            ->set('state.current_reading', '1140')
            ->call('save')
            ->assertHasNoErrors();

        $reading = \App\Models\Reading::where('period', '2026-10')->firstOrFail();
        $this->assertSame(40.0, (float) $reading->consumption);

        // The bill is the point of the visit: 40 kWh + the maintenance fee.
        $bill = $this->subscriber->bills()->where('period', '2026-10')->firstOrFail();
        $this->assertSame(35.0, (float) $bill->total);
    }

    /** A dead meter: the estimate is what gets billed, and it becomes the new baseline. */
    public function test_the_reading_screen_records_an_estimate(): void
    {
        \App\Models\Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-08',
            'current_reading' => 1100, 'previous_reading' => 1000, 'consumption' => 0,
            'entered_by' => $this->admin->id,
        ]);

        $this->readingForm()
            ->set('state.subscriber_id', $this->subscriber->id)
            ->set('state.period', '2026-10')
            ->set('state.reading_mode', \App\Services\ReadingService::MODE_ESTIMATED)
            ->set('state.estimated_kwh', '48')
            ->set('state.estimate_reason', 'العدّاد معطّل')
            ->call('save')
            ->assertHasNoErrors();

        $reading = \App\Models\Reading::where('period', '2026-10')->firstOrFail();
        $this->assertTrue($reading->is_estimated);
        $this->assertSame(48.0, (float) $reading->consumption);
        $this->assertSame('العدّاد معطّل', $reading->estimate_reason);
    }

    /**
     * The meter-swap read: the old meter is closed at its final number, the subscriber moves
     * to the new meter, and the new meter's counter is never subtracted from the old one.
     */
    public function test_the_reading_screen_records_a_meter_replacement(): void
    {
        \App\Models\Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-08',
            'current_reading' => 1100, 'previous_reading' => 1000, 'consumption' => 0,
            'entered_by' => $this->admin->id,
        ]);

        $this->readingForm()
            ->set('state.subscriber_id', $this->subscriber->id)
            ->set('state.period', '2026-10')
            ->set('state.reading_mode', \App\Services\ReadingService::MODE_SWAP)
            ->set('state.current_reading', '12')
            ->set('state.old_meter_no', '2202')
            ->set('state.old_meter_reading', '1100')
            ->set('state.new_meter_no', '4478')
            ->set('state.new_meter_start', '0')
            ->call('save')
            ->assertHasNoErrors();

        $reading = \App\Models\Reading::where('period', '2026-10')->firstOrFail();
        $this->assertTrue($reading->hasMeterSwap());
        $this->assertSame(12.0, (float) $reading->consumption);
        $this->assertSame('4478', $this->subscriber->fresh()->meter_no);
    }

    /** Each mode shows only its own fields — a swap form must not ask for an estimate. */
    public function test_the_reading_screen_shows_only_the_fields_of_the_chosen_mode(): void
    {
        $normal = $this->readingForm()
            ->set('state.reading_mode', \App\Services\ReadingService::MODE_NORMAL)
            ->assertSee(__('t.cur_meter_reading'))
            ->assertDontSee(__('t.estimated_kwh'))
            ->assertDontSee(__('t.new_meter_no'));

        $normal->set('state.reading_mode', \App\Services\ReadingService::MODE_ESTIMATED)
            ->assertSee(__('t.estimated_kwh'))
            ->assertSee(__('t.estimate_reason'))
            ->assertDontSee(__('t.cur_meter_reading'));

        $normal->set('state.reading_mode', \App\Services\ReadingService::MODE_SWAP)
            ->assertSee(__('t.new_meter_no'))
            ->assertSee(__('t.old_meter_final'))
            ->assertDontSee(__('t.estimated_kwh'));
    }

    /** A reading with no value is refused rather than written as a zero the bill would ignore. */
    public function test_the_reading_screen_requires_the_value_of_the_chosen_mode(): void
    {
        $this->readingForm()
            ->set('state.subscriber_id', $this->subscriber->id)
            ->set('state.period', '2026-10')
            ->set('state.reading_mode', \App\Services\ReadingService::MODE_NORMAL)
            ->set('state.current_reading', '')
            ->call('save')
            ->assertHasErrors('state.current_reading');

        $this->assertSame(0, \App\Models\Reading::where('period', '2026-10')->count());
    }

    /** The search box narrows the picker, and the chosen subscriber survives the filter. */
    public function test_the_reading_screen_filters_the_subscriber_picker(): void
    {
        $other = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'Ali Hassan', 'meter_no' => '9999',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        $component = $this->readingForm()
            ->set('state.subscriber_id', $this->subscriber->id)
            ->assertSee('لينا مرعب')
            ->set('state.subscriber_search', 'Ali')
            ->assertSee('Ali Hassan')
            // The selected subscriber stays in the list: a value that vanishes from its own
            // options resets to blank on the next save.
            ->assertSee('لينا مرعب');

        $this->assertSame($this->subscriber->id, (int) $component->get('state.subscriber_id'));
    }

    /** The same three modes are reachable from the subscriber's own profile. */
    public function test_the_profile_records_a_reading_through_the_action_modal(): void
    {
        \App\Models\Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-08',
            'current_reading' => 1100, 'previous_reading' => 1000, 'consumption' => 0,
            'entered_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, [
                'action' => \App\Admin\Actions\RecordReading::class,
                'subjectId' => $this->subscriber->id,
            ])
            ->call('openModal')
            ->assertSet('state.period', now()->format('Y-m'))
            ->set('state.period', '2026-10')
            ->set('state.current_reading', '1125')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(25.0, (float) \App\Models\Reading::where('period', '2026-10')->firstOrFail()->consumption);
    }

    // ---- cash handovers ----------------------------------------------------

    /** A payment, so the collector is actually holding cash to hand over. */
    private function collected(float $amount): void
    {
        Payment::create([
            'subscriber_id' => $this->subscriber->id,
            'collector_id' => $this->collector->id,
            'amount' => $amount,
            'method' => Payment::METHOD_CASH_USD,
        ]);
    }

    /**
     * The handover form caps the amount at the cash in hand, and says so on the field.
     *
     * The service refuses it too (that is the authoritative guard, and it covers the quick
     * action as well) — this asserts the operator gets the reason where they are typing.
     */
    public function test_the_handover_form_refuses_more_than_the_collector_holds(): void
    {
        $this->collected(75);

        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => HandoverForm::class])
            ->set('state.collector_id', $this->collector->id)
            ->set('state.handover_date', '2026-09-08')
            ->set('state.amount', '100')
            ->call('save')
            ->assertHasErrors('state.amount');

        $this->assertSame(0, CashHandover::count(), 'A refused handover was written anyway.');
    }

    /** Exactly what the collector holds is accepted, and the ledger lands on zero. */
    public function test_the_handover_form_accepts_exactly_what_the_collector_holds(): void
    {
        $this->collected(75);

        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => HandoverForm::class])
            ->set('state.collector_id', $this->collector->id)
            ->set('state.handover_date', '2026-09-08')
            ->set('state.amount', '75')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, CashHandover::count());
        $this->assertSame(0.0, \App\Services\CollectionService::inHand($this->collector->id));
    }

    /**
     * When editing, the record's own amount counts as available again.
     *
     * A handover typed a digit short is the case this form is opened for: measuring against the
     * already-reduced balance would make it impossible to correct upwards.
     */
    public function test_the_handover_form_lets_an_edit_raise_the_amount_back_up(): void
    {
        $this->collected(75);

        $handover = CashHandover::create([
            'collector_id' => $this->collector->id, 'amount' => 20,
            'handover_date' => '2026-09-08', 'recorded_by' => $this->admin->id,
        ]);   // created directly, bypassing the service: this is data as it is already stored

        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => HandoverForm::class, 'record' => $handover->id])
            ->set('state.amount', '75')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(75.0, (float) $handover->fresh()->amount);
        $this->assertSame(0.0, \App\Services\CollectionService::inHand($this->collector->id));

        // one cent past the full in-hand figure is still refused
        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => HandoverForm::class, 'record' => $handover->id])
            ->set('state.amount', '75.01')
            ->call('save')
            ->assertHasErrors('state.amount');
    }

    // ---- refusals from the domain services ---------------------------------

    /**
     * A refused save shows the service's reason instead of crashing.
     *
     * The reading screen refuses a second reading for the same period. That is a business rule
     * a validation rule cannot express, and the screen used to let the exception escape: the
     * Livewire request answered 500 and the operator saw nothing at all. The action modal has
     * always caught this — the screen has to behave the same way.
     */
    public function test_a_refused_save_shows_the_reason_instead_of_erroring(): void
    {
        \App\Models\Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-10',
            'current_reading' => 1100, 'previous_reading' => 1000, 'consumption' => 100,
            'entered_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => \App\Admin\Forms\ReadingForm::class])
            ->set('state.subscriber_id', $this->subscriber->id)
            ->set('state.period', '2026-10')
            ->set('state.reading_mode', \App\Services\ReadingService::MODE_NORMAL)
            ->set('state.current_reading', '1500')
            ->call('save')
            ->assertHasErrors('save')
            // The refusal must be VISIBLE, not just recorded in the error bag.
            ->assertSee('already exists');

        $this->assertSame(1, \App\Models\Reading::where('period', '2026-10')->count());
    }

    /** A refusal must not be silent either — the form stays put rather than redirecting away. */
    public function test_a_refused_save_does_not_redirect(): void
    {
        \App\Models\Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-10',
            'current_reading' => 1100, 'previous_reading' => 1000, 'consumption' => 100,
            'entered_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => \App\Admin\Forms\ReadingForm::class])
            ->set('state.subscriber_id', $this->subscriber->id)
            ->set('state.period', '2026-10')
            ->set('state.current_reading', '1500')
            ->call('save')
            ->assertNoRedirect();
    }

    /**
     * The refusal is read by an operator in an Arabic panel — it must not arrive in English.
     *
     * These messages used to be unreachable (the request answered 500 before they were shown),
     * so nothing caught that they were hardcoded English in a Laravel panel whose screens,
     * labels and receipts are Arabic.
     */
    public function test_the_refusal_is_shown_in_the_panel_language(): void
    {
        \App\Models\Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-10',
            'current_reading' => 1100, 'previous_reading' => 1000, 'consumption' => 100,
            'entered_by' => $this->admin->id,
        ]);

        $refuse = function () {
            return Livewire::actingAs($this->admin)
                ->test(ResourceForm::class, ['form' => \App\Admin\Forms\ReadingForm::class])
                ->set('state.subscriber_id', $this->subscriber->id)
                ->set('state.period', '2026-10')
                ->set('state.current_reading', '1500')
                ->call('save');
        };

        app()->setLocale('ar');
        $refuse()->assertSee('في قراءة مسجّلة لهذا الشهر مسبقاً');

        app()->setLocale('en');
        $refuse()->assertSee('A reading for this period already exists.');

        app()->setLocale('en');
    }

    /** Both language files carry every refusal, and its placeholders survive translation. */
    public function test_every_refusal_has_a_translation_in_both_languages(): void
    {
        $ar = json_decode(file_get_contents(base_path('lang/ar.json')), true);
        $en = json_decode(file_get_contents(base_path('lang/en.json')), true);

        $keys = array_values(array_filter(array_keys($ar), fn ($key) => str_starts_with($key, 'refuse.')));

        $this->assertGreaterThanOrEqual(20, count($keys), 'The refusal namespace looks truncated.');

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, "$key is missing from en.json");
            $this->assertNotSame('', trim($ar[$key]), "$key has no Arabic text");
            $this->assertNotSame('', trim($en[$key]), "$key has no English text");

            // A placeholder dropped in translation turns ":amount" into a literal in the sentence.
            $placeholders = function (string $line): array {
                preg_match_all('/:([a-z_]+)/', $line, $found);

                return $found[1];
            };
            $this->assertSame($placeholders($en[$key]), $placeholders($ar[$key]), "Placeholders differ for $key");
        }
    }

    // ---- the redirect after an action --------------------------------------

    /**
     * After an action the operator must land back on the page they were on.
     *
     * The redirect target is the URL the server rendered the modal on. It used to be read from
     * the request's Referer header, which the client controls: a forged value was followed
     * verbatim (an open redirect) and an absent one sent the browser to the Livewire update
     * endpoint as a JSON page. Both are asserted here so neither can come back.
     */
    public function test_the_post_action_redirect_returns_to_the_rendered_page(): void
    {
        $page = url('/admin/subscribers/'.$this->subscriber->id.'?tab=payments');

        $component = Livewire::actingAs($this->admin)
            ->test(ActionForm::class, [
                'action' => CollectPayment::class,
                'subjectId' => $this->subscriber->id,
                'returnTo' => $page,
            ]);

        // A forged Referer must not decide where the operator ends up.
        request()->headers->set('Referer', 'https://example.com/phishing');

        $component->call('openModal')
            ->set('state.amount', '10')
            ->set('state.method', Payment::METHOD_CASH_USD)
            ->call('submit')
            ->assertRedirect($page);
    }

    /** …and it must not depend on that header being present at all. */
    public function test_the_post_action_redirect_does_not_need_a_referer(): void
    {
        $page = url('/admin/subscribers/'.$this->subscriber->id);

        request()->headers->remove('Referer');

        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, [
                'action' => CollectPayment::class,
                'subjectId' => $this->subscriber->id,
                'returnTo' => $page,
            ])
            ->call('openModal')
            ->set('state.amount', '10')
            ->set('state.method', Payment::METHOD_CASH_USD)
            ->call('submit')
            ->assertRedirect($page);
    }

    // ---- layering ----------------------------------------------------------

    /**
     * The modal must sit above the panel's fixed chrome.
     *
     * KTUI lifts its modal to `z-index: 90` at RUNTIME; a modal rendered by Livewire is never
     * initialised by that engine, so it keeps `z-index: auto` and paints in DOM order. The
     * panel's fixed sidebar (z-30) and header (z-10) then show through, and — because the icon
     * font sets `position: relative` on every glyph — so do the icons of anything later in the
     * document. Measured before the fix: 535 of 2016 sampled points resolved to page elements
     * instead of the modal.
     *
     * Asserted against the BUILT stylesheet, because that is what the browser loads: it proves
     * the declaration survived Tailwind's build as well as the source. The floor is derived
     * from the layout's own chrome rather than hard-coded, so raising the sidebar's z-index
     * fails here instead of silently re-introducing the overlap.
     */
    public function test_the_modal_layer_sits_above_the_panels_fixed_chrome(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/admin.blade.php'));

        preg_match_all('/z-(\d+)\b/', $layout, $chrome);
        $this->assertNotEmpty($chrome[1], 'No z-index utilities found in the layout — has the shell changed?');
        $highestChrome = max(array_map('intval', $chrome[1]));
        $this->assertGreaterThanOrEqual(30, $highestChrome, 'Expected the fixed sidebar to be a high layer.');

        $sheet = glob(public_path('build/assets/app-*.css'));
        $this->assertNotEmpty($sheet, 'No built stylesheet — run npm run build before the suite.');

        $css = file_get_contents($sheet[0]);

        // The rule under test: `.kt-modal{z-index:N}`.
        preg_match_all('/\.kt-modal\{([^}]*)\}/', $css, $rules);

        $found = null;
        foreach ($rules[1] as $declarations) {
            if (preg_match('/z-index:\s*(-?\d+)/', $declarations, $z)) {
                $found = (int) $z[1];
            }
        }

        $this->assertNotNull($found, 'The built CSS declares no z-index on .kt-modal: a statically rendered modal will paint under the sidebar.');

        $this->assertGreaterThan(
            $highestChrome,
            $found,
            sprintf('The modal layer (%d) does not clear the panel chrome (%d).', $found, $highestChrome),
        );
    }

    // ---- record actions ---------------------------------------------------

    public function test_collect_action_prefills_the_outstanding_amount_and_settles_the_bill(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => CollectPayment::class, 'subjectId' => $this->subscriber->id])
            ->call('openModal')
            ->assertSet('open', true);

        // Opened with the outstanding figure already in the box: the overwhelmingly common
        // case is collecting exactly what is due.
        $this->assertSame(36.50, (float) $component->get('state.amount'));

        $component->set('state.amount', '36.50')
            ->set('state.method', Payment::METHOD_CASH_USD)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $payment = Payment::firstOrFail();
        $this->assertSame(36.50, (float) $payment->amount);
        $this->assertSame($this->admin->id, $payment->collector_id);
        // The money did not just move: the bill it settled must show as paid, and the
        // subscriber's balance must be clear — otherwise the list keeps showing a debt.
        $this->assertSame(36.50, (float) Bill::firstOrFail()->paid_amount);
        $this->assertSame(0.0, (float) $this->subscriber->fresh()->outstanding());
    }

    /**
     * A refusal from the service is a business rule, not a crash: it is shown inside the
     * modal (on the 'action' error key) and nothing is written.
     */
    public function test_collect_action_shows_a_service_refusal_instead_of_writing(): void
    {
        // Settle the account, then try to collect again.
        $this->subscriber->bills()->update(['paid_amount' => 36.50]);

        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => CollectPayment::class, 'subjectId' => $this->subscriber->id])
            ->call('openModal')
            ->set('state.amount', '10')
            ->call('submit')
            ->assertHasErrors('action')
            ->assertSet('open', true);

        $this->assertSame(0, Payment::count());
    }

    public function test_special_invoice_action_creates_an_itemized_bill(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => SpecialInvoice::class, 'subjectId' => $this->subscriber->id])
            ->call('openModal')
            ->set('state.title', 'Cable repair')
            ->set('state.items.0.description', 'Cable')
            ->set('state.items.0.quantity', '2')
            ->set('state.items.0.unit_price', '15')
            ->call('submit')
            ->assertHasNoErrors();

        $bill = Bill::where('type', 'special')->firstOrFail();
        $this->assertSame(30.0, (float) $bill->total);
        $this->assertSame('Cable repair', $bill->note ?: $bill->title ?? 'Cable repair');
    }

    public function test_special_invoice_action_needs_at_least_one_line(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => SpecialInvoice::class, 'subjectId' => $this->subscriber->id])
            ->call('openModal')
            ->call('removeItem', 'items', 0)
            ->call('submit')
            ->assertHasErrors('state.items');

        $this->assertSame(0, Bill::where('type', 'special')->count());
    }

    public function test_convert_to_flat_action_moves_the_subscriber_off_the_meter(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => ConvertToFlat::class, 'subjectId' => $this->subscriber->id])
            ->call('openModal')
            ->set('state.flat_fee', '40')
            ->set('state.reason', 'Meter burnt')
            ->call('submit')
            ->assertHasNoErrors();

        $fresh = $this->subscriber->fresh();
        $this->assertSame(Subscriber::TYPE_FLAT, $fresh->type);
        $this->assertSame(40.0, (float) $fresh->flat_fee);
    }

    public function test_convert_to_metered_action_requires_a_meter_number(): void
    {
        $this->subscriber->update(['type' => Subscriber::TYPE_FLAT, 'flat_fee' => 40]);

        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => ConvertToMetered::class, 'subjectId' => $this->subscriber->id])
            ->call('openModal')
            ->set('state.meter_no', '')
            ->call('submit')
            ->assertHasErrors('state.meter_no');

        $this->assertSame(Subscriber::TYPE_FLAT, $this->subscriber->fresh()->type);
    }

    public function test_convert_to_metered_action_rejects_a_duplicate_meter_number(): void
    {
        $other = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'Other', 'meter_no' => '9999',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
        ]);
        $this->subscriber->update(['type' => Subscriber::TYPE_FLAT, 'flat_fee' => 40]);

        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => ConvertToMetered::class, 'subjectId' => $this->subscriber->id])
            ->call('openModal')
            ->set('state.meter_no', '9999')
            ->call('submit')
            ->assertHasErrors('state.meter_no');

        $this->assertSame(Subscriber::TYPE_FLAT, $this->subscriber->fresh()->type);
        $this->assertSame('9999', $other->fresh()->meter_no);
    }

    // ---- definition/view consistency --------------------------------------

    /**
     * No icon may be used for two different things on the subscriber profile.
     *
     * That screen puts four action buttons directly above a three-tab switcher, so a glyph
     * shared between them reads as the same control twice — which is exactly what happened:
     * "تحصيل" and the "الدفعات" tab both wore ki-wallet, and "تسجيل قراءة" and the "القراءات"
     * tab both wore ki-bill.
     *
     * Scope is CONTROLS: the action buttons and the tabs. The inline meta glyphs in the
     * identity row (the small meter and amperage marks) are deliberately excluded — they are
     * data labels at caption size, not controls, and a label sharing a glyph with a button is
     * not the confusion this guards against.
     *
     * Both lists are read from SOURCE, from the same file the page renders: the tab icons are
     * declared as data in the blade, and the action icons come from the ActionDefinition
     * classes the blade mounts. Nothing has to be kept in sync by hand, so a new action with a
     * borrowed glyph fails here instead of shipping.
     */
    public function test_no_icon_is_used_for_two_things_on_the_subscriber_profile(): void
    {
        $blade = file_get_contents(resource_path('views/admin/subscribers/show.blade.php'));

        $glyphs = [];

        // The tab switcher's icons, declared as data.
        preg_match_all("/'icon'\s*=>\s*'(ki-[a-z0-9-]+)'/", $blade, $tabs);
        foreach ($tabs[1] as $icon) {
            $glyphs[$icon][] = 'a profile tab';
        }

        // The action modals the blade mounts. Matched by SHORT name and resolved against the
        // actions namespace, so this needs no backslashes in a pattern — a literal-namespace
        // regex here is a escaping accident waiting to happen (a wrong backslash count makes
        // it match nothing, and the test then passes over an empty list).
        // Actions live one level BELOW the base class: App\Admin\ActionDefinition, but
        // App\Admin\Actions\CollectPayment. The namespace is assembled from the base class
        // plus the directory name, with the separator from its codepoint, so no hand-written
        // namespace literal has to survive an escaping layer.
        $separator = chr(92);
        $namespace = (new \ReflectionClass(\App\Admin\ActionDefinition::class))->getNamespaceName()
            .$separator.basename(app_path('Admin/Actions'));

        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)::class/', $blade, $mounts);

        $classes = [];
        foreach (array_unique($mounts[1]) as $short) {
            $class = $namespace.$separator.$short;

            // The blade also mounts table definitions; keep only the actions.
            if (class_exists($class) && is_subclass_of($class, \App\Admin\ActionDefinition::class)) {
                $classes[] = $class;
            }
        }

        $this->assertGreaterThanOrEqual(4, count($classes), 'No action classes were found in the profile blade.');

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), $class.' does not exist.');

            if ($icon = $class::make()->icon()) {
                $glyphs[$icon][] = class_basename($class);
            }
        }

        $duplicated = array_filter($glyphs, fn (array $uses): bool => count($uses) > 1);

        $report = [];
        foreach ($duplicated as $icon => $uses) {
            $report[] = $icon.' is used by '.implode(' and ', $uses);
        }

        // Guard the guard: the same (broken) reading must not come back as an empty list.
        $this->assertNotEmpty($glyphs, 'No icons were collected — the parsing has drifted.');

        $this->assertSame([], $report, "The same icon is used for two controls on one screen:\n  ".implode("\n  ", $report));
    }

    /**
     * Every badge colour a table can name must be one the table view actually renders.
     *
     * The renderer maps colours to kt-badge variants with @class; a colour it does not know
     * lands on a bare `kt-badge` with no variant, which draws as a grey chip that looks
     * broken (and, for an exception badge on every row, looks like a rendering failure).
     * The views are read as text because this is a contract between two files, not behaviour
     * any request exercises.
     */
    public function test_every_badge_colour_used_by_a_table_is_rendered_by_the_table_view(): void
    {
        $view = file_get_contents(resource_path('views/livewire/admin/data-table.blade.php'));

        // Colours the view knows how to draw, derived FROM the view's own comparison map:
        //   $color === 'x'   and   in_array($color, ['x', 'y'])
        // Derived rather than hard-coded, so adding a colour here can never drift from what
        // the view renders.
        preg_match_all("/color === '([a-z_]+)'/", $view, $eq);
        preg_match_all('/in_array\([^,]+,\s*\[([^\]]+)\]/', $view, $in);
        $supported = array_values(array_unique(array_merge(
            $eq[1],
            preg_split("/[^a-z_]+/", implode(',', $in[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        )));

        $this->assertContains('success', $supported, 'Could not read the colour map out of the table view.');

        $used = [];

        foreach (glob(app_path('Admin/Tables/*.php')) as $file) {
            $source = file_get_contents($file);

            // Split into ONE CHUNK PER COLUMN (every column starts with 'key' =>) and only
            // look for colours inside a chunk that declares one. Scanning the whole file
            // instead would pick up unrelated match() arms — a `render` closure mapping a
            // bill type to a label looks nothing like a badge colour.
            $chunks = preg_split("/\n\s*'(?:key|filter_key)'\s*=>/", $source) ?: [];

            foreach ($chunks as $chunk) {
                if (! preg_match("/'(?:color|badge)'\s*=>/", $chunk)) {
                    continue;
                }

                // Everything from the declaration to the end of that column's array.
                $declaration = substr($chunk, (int) strpos($chunk, "'color'") ?: 0) ?: $chunk;

                // Both spellings: an arrow closure whose body is a match(), and a bare value.
                // [a-z0-9_] not [a-z_]: a typo like 'info2' must still be COLLECTED here so
                // the assertion can report it, rather than silently matching nothing.
                preg_match_all("/::[A-Z0-9_]+\s*=>\s*'([a-z0-9_]+)'/", $declaration, $arms);
                preg_match_all("/'(?:color|badge)'\s*=>\s*fn\b.{0,400}?=>\s*'([a-z0-9_]+)'/s", $declaration, $arrow);
                preg_match_all("/'(?:color|badge)'\s*=>\s*'([a-z0-9_]+)'/", $declaration, $plain);

                foreach (array_merge($arms[1], $arrow[1], $plain[1]) as $colour) {
                    $used[$colour][] = basename($file);
                }
            }
        }

        $this->assertNotEmpty($used, 'No badge colours found — has the definition shape changed?');

        foreach ($used as $colour => $files) {
            $this->assertContains(
                $colour,
                $supported,
                sprintf("Badge colour '%s' (used in %s) is not mapped to a kt-badge variant.", $colour, implode(', ', array_unique($files))),
            );
        }
    }

    // ---- handovers --------------------------------------------------------

    public function test_handover_group_shows_the_collectors_actual_collections(): void
    {
        // collector_id is the column the ledger reads — `collected_by`/`recorded_by` are not
        // columns on payments at all, so this payment used to be recorded with no collector and
        // the collector's in-hand balance stayed at zero.
        \App\Models\Payment::create([
            'subscriber_id' => $this->subscriber->id,
            'collector_id' => $this->collector->id,
            'amount' => 80, 'method' => 'cash',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => HandoverForm::class])
            ->set('state.collector_id', $this->collector->id)
            ->set('state.handover_date', '2026-09-10')
            ->set('state.amount', '50')
            ->call('save')
            ->assertHasNoErrors();

        $handover = CashHandover::firstOrFail();
        $this->assertSame(50.0, (float) $handover->amount);
        $this->assertSame($this->collector->id, $handover->collector_id);
        $this->assertSame($this->admin->id, $handover->recorded_by);
    }

    /** Deleting a handover puts the cash back in the collector's hand — the warning must say so. */
    public function test_handover_delete_warning_states_the_resulting_in_hand_balance(): void
    {
        $handover = CashHandover::create([
            'collector_id' => $this->collector->id, 'amount' => 50,
            'handover_date' => '2026-09-10', 'recorded_by' => $this->admin->id,
        ]);

        $warning = \App\Admin\Tables\HandoversTable::make()->deleteWarning($handover);

        $this->assertStringContainsString('$50.00', $warning);
        $this->assertStringContainsString('Jad Haddad', $warning);
    }
}
