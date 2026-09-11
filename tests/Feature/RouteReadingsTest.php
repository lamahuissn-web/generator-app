<?php

namespace Tests\Feature;

use App\Livewire\RouteReadingSheet;
use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The route readings worklist: one screen, one save, a street at a time.
 *
 * The behaviours worth pinning are the ones a batch can get catastrophically wrong —
 * losing the rows that did save because one row was refused, reading the same house twice,
 * or refusing a reading that is merely unusual.
 */
class RouteReadingsTest extends TestCase
{
    use RefreshDatabase;

    private User $collector;

    private Route $route;

    private Subscriber $first;

    private Subscriber $second;

    private Subscriber $third;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);
        $this->collector = User::create(['name' => 'Jad', 'email' => 'jad@x.com', 'password' => 'x', 'role' => 'collector', 'locale' => 'en']);

        $this->period = BillingService::currentPeriod();

        $this->route = Route::create(['name' => 'Ramlet El Baida']);
        $this->first = $this->subscriber('أبو علي حسن', '4478');
        $this->second = $this->subscriber('سمير خليل', '2011');
        $this->third = $this->subscriber('ليلى نصر', '3390');
    }

    // ---- fixtures ------------------------------------------------------------

    private function subscriber(
        string $name,
        string $meter,
        string $type = Subscriber::TYPE_METERED,
        string $status = Subscriber::STATUS_ACTIVE,
    ): Subscriber {
        return Subscriber::create([
            'route_id' => $this->route->id,
            'name' => $name,
            'meter_no' => $type === Subscriber::TYPE_METERED ? $meter : null,
            'type' => $type,
            'flat_fee' => $type === Subscriber::TYPE_FLAT ? 50 : null,
            'amperage' => 10,
            // Exempt: these tests are about the reading flow, not the fee arithmetic.
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
            'status' => $status,
        ]);
    }

    /** A reading as the service would have written it, without touching the bill. */
    private function reading(Subscriber $subscriber, string $period, float $value, float $consumption): Reading
    {
        return Reading::create([
            'subscriber_id' => $subscriber->id,
            'period' => $period,
            'current_reading' => $value,
            'previous_reading' => $value - $consumption,
            'consumption' => $consumption,
        ]);
    }

    private function pastMonth(int $monthsBack): string
    {
        return now()->startOfMonth()->subMonths($monthsBack)->format('Y-m');
    }

    private function row($component, Subscriber $subscriber): array
    {
        return collect($component->instance()->rows())->firstWhere('id', $subscriber->id);
    }

    // ---- the screen ----------------------------------------------------------

    public function test_guest_is_redirected_from_the_route_readings_screen(): void
    {
        $this->get(route('collector.route.readings', $this->route))->assertRedirect(route('login'));
    }

    public function test_the_screen_lists_the_metered_subscribers_and_marks_who_is_left(): void
    {
        $this->reading($this->first, $this->period, 1496, 373);

        $this->actingAs($this->collector)
            ->get(route('collector.route.readings', $this->route))
            ->assertOk()
            ->assertSee('أبو علي حسن')
            ->assertSee('سمير خليل')
            ->assertSee('Recorded: 1 / 3')
            ->assertSee('Save readings');
    }

    public function test_the_route_page_opens_the_worklist_and_marks_every_metered_row(): void
    {
        $this->reading($this->first, $this->period, 1496, 373);

        $this->actingAs($this->collector)
            ->get(route('collector.route.show', $this->route))
            ->assertOk()
            ->assertSee('Route readings')
            ->assertSee('Recorded: 1 / 3')
            ->assertSee('Not read');
    }

    public function test_pending_rows_come_first_and_the_progress_says_how_much_is_left(): void
    {
        $this->reading($this->third, $this->period, 2400, 296);

        $this->actingAs($this->collector);
        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route]);

        $this->assertSame(['total' => 3, 'done' => 1, 'pending' => 2], $component->instance()->progress());

        $names = collect($component->instance()->rows())->pluck('name');
        $this->assertSame('ليلى نصر', $names->last(), 'a recorded house drops to the bottom of the walk');
    }

    public function test_the_screen_names_the_subscribers_it_will_never_offer_a_reading_for(): void
    {
        $this->subscriber('Flat Shop', '', Subscriber::TYPE_FLAT);
        $this->subscriber('Stopped House', '9999', Subscriber::TYPE_METERED, Subscriber::STATUS_INACTIVE);

        $this->actingAs($this->collector);
        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route]);

        // Only live meters are in the worklist…
        $this->assertSame(3, $component->instance()->progress()['total']);

        // …and the two who are not get counted instead of silently disappearing.
        $component->assertSee('flat-rate subscriber (no meter)')
            ->assertSee('inactive metered subscriber')
            ->assertDontSee('Flat Shop');
    }

    // ---- the save ------------------------------------------------------------

    public function test_one_save_records_every_filled_row_and_leaves_the_blank_ones_pending(): void
    {
        $this->actingAs($this->collector);

        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->set("values.{$this->first->id}", 1496)
            ->set("values.{$this->second->id}", 1120);

        $this->assertSame(2, $component->instance()->typedCount());

        $component->call('save');

        $component->assertSet('savedCount', 2);

        $this->assertDatabaseHas('readings', [
            'subscriber_id' => $this->first->id, 'period' => $this->period, 'current_reading' => 1496,
        ]);
        $this->assertDatabaseHas('readings', [
            'subscriber_id' => $this->second->id, 'period' => $this->period, 'current_reading' => 1120,
        ]);

        // The house nobody answered is untouched, and each recorded reading billed once.
        $this->assertDatabaseMissing('readings', ['subscriber_id' => $this->third->id, 'period' => $this->period]);
        $this->assertSame(2, Bill::where('period', $this->period)->count());
        $this->assertSame(1, $component->instance()->progress()['pending']);
    }

    public function test_a_refused_row_is_reported_and_does_not_cost_the_rest_of_the_batch(): void
    {
        $this->reading($this->first, $this->pastMonth(1), 1500, 400);
        $this->reading($this->second, $this->pastMonth(1), 900, 200);

        $this->actingAs($this->collector);

        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->set("values.{$this->first->id}", 1490)   // below the previous 1500: refused
            ->set("values.{$this->second->id}", 1120); // fine

        $component->call('save');

        $component->assertSet('savedCount', 1);
        $this->assertDatabaseHas('readings', ['subscriber_id' => $this->second->id, 'period' => $this->period]);
        $this->assertDatabaseMissing('readings', ['subscriber_id' => $this->first->id, 'period' => $this->period]);

        // The refusal is attached to the house it belongs to…
        $failed = $component->get('failed');
        $this->assertArrayHasKey($this->first->id, $failed);
        $this->assertSame('أبو علي حسن', $failed[$this->first->id]['name']);

        // …and its value stays typed so it can be corrected without re-walking the street.
        $this->assertSame('1490', (string) $component->get('values')[$this->first->id]);
        $this->assertArrayNotHasKey($this->second->id, $component->get('values'), 'a saved row is cleared');
    }

    public function test_a_house_already_recorded_this_cycle_is_never_recorded_twice(): void
    {
        $this->reading($this->first, $this->period, 1496, 373);

        $this->actingAs($this->collector);

        // A stale page can still post a value into an already-recorded row.
        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->set("values.{$this->first->id}", 1999)
            ->set("values.{$this->second->id}", 1120);

        $component->call('save');

        $component->assertSet('savedCount', 1);
        $this->assertSame(1, Reading::where('subscriber_id', $this->first->id)->count());
        $this->assertSame(1496.0, (float) Reading::where('subscriber_id', $this->first->id)->value('current_reading'));
    }

    public function test_saving_with_nothing_typed_does_not_write_and_says_so(): void
    {
        $this->actingAs($this->collector);

        Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->call('save')
            ->assertSet('savedCount', null)
            ->assertSee('Nothing typed yet');

        $this->assertSame(0, Reading::count());
    }

    /**
     * The save button is never disabled by the server's own count.
     *
     * A field is bound with wire:model.blur, so while the collector is typing the server still sees
     * zero typed rows. A `@disabled($typed === 0)` on the button therefore arrives at the browser as
     * a disabled control, and the browser dispatches no click at all on a disabled button: the
     * collector typed three meters, pressed Save, and nothing happened — no request, no message,
     * nothing to see. The empty-save guard belongs in save(), which answers with a message.
     */
    public function test_the_save_button_is_never_disabled_while_values_are_still_only_in_the_browser(): void
    {
        $this->actingAs($this->collector);

        $html = Livewire::test(RouteReadingSheet::class, ['route' => $this->route])->html();

        $tag = null;
        if (preg_match('/<button[^>]*wire:click="save"[^>]*>/', $html, $m) === 1) {
            $tag = $m[0];
        }

        $this->assertNotNull($tag, 'The save button is the screen\'s whole point — it must be there.');

        // A bare `disabled` attribute, not the loading guard's own wire:loading.attr="disabled".
        $this->assertSame(
            0,
            preg_match('/(?<![\w=".])disabled(?![\w="-])/', $tag),
            'A disabled attribute is set on the save button, so a click cannot reach it: '.$tag,
        );

        // The guard left in place watches save's own request and nothing else.
        $this->assertStringContainsString('wire:loading.attr="disabled"', $tag);
        $this->assertStringContainsString('wire:target="save"', $tag);
    }

    // ---- what the collector sees before saving --------------------------------

    public function test_a_value_far_above_the_house_average_is_flagged_but_still_recorded(): void
    {
        // Three real cycles around 100 kWh: a 400 kWh month is worth a look, not a refusal.
        foreach ([3, 2, 1] as $back) {
            $this->reading($this->first, $this->pastMonth($back), 1000 + (3 - $back) * 100, 100);
        }

        $this->actingAs($this->collector);

        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->set("values.{$this->first->id}", 1600);

        $row = $this->row($component, $this->first);
        $this->assertSame('jump', $row['preview']['warning']);
        $this->assertSame(100.0, $row['preview']['average']);

        $component->call('save');

        $component->assertSet('savedCount', 1);
        $this->assertDatabaseHas('readings', [
            'subscriber_id' => $this->first->id, 'period' => $this->period, 'consumption' => 400,
        ]);
    }

    public function test_a_value_equal_to_the_last_reading_is_flagged_as_zero_consumption(): void
    {
        $this->reading($this->second, $this->pastMonth(1), 880, 120);

        $this->actingAs($this->collector);

        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->set("values.{$this->second->id}", 880);

        $this->assertSame('zero', $this->row($component, $this->second)['preview']['warning']);
        $component->assertSee('Zero consumption');
    }

    public function test_a_value_below_the_last_reading_is_flagged_before_the_save(): void
    {
        $this->reading($this->second, $this->pastMonth(1), 880, 120);

        $this->actingAs($this->collector);

        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->set("values.{$this->second->id}", 500);

        $row = $this->row($component, $this->second);
        $this->assertSame('lower', $row['preview']['warning']);
        $this->assertSame(-380.0, $row['preview']['consumption']);
        $component->assertSee('Below the previous reading');
    }

    public function test_the_save_bar_sums_what_is_about_to_be_billed(): void
    {
        $this->reading($this->first, $this->pastMonth(1), 1000, 400);
        $this->reading($this->second, $this->pastMonth(1), 500, 200);

        $this->actingAs($this->collector);

        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->set("values.{$this->first->id}", 1200)  // 200 kWh
            ->set("values.{$this->second->id}", 800); // 300 kWh

        $price = BillingService::kwhPrice($this->period);

        $this->assertSame(2, $component->instance()->typedCount());
        $this->assertSame(round(500 * $price, 2), $component->instance()->typedTotal());
    }

    // ---- what a dead meter does ------------------------------------------------

    public function test_a_dead_meter_can_be_reported_from_the_worklist_and_undone(): void
    {
        $this->actingAs($this->collector);

        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->call('reportBroken', $this->third->id);

        $this->assertNotNull($this->third->fresh()->meter_broken_reported_at);
        $this->assertTrue($this->row($component, $this->third)['broken']);

        $component->call('clearBroken', $this->third->id);

        $this->assertNull($this->third->fresh()->meter_broken_reported_at);
    }

    public function test_a_row_with_a_value_typed_is_not_also_reported_as_dead(): void
    {
        $this->actingAs($this->collector);

        Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->set("values.{$this->third->id}", 2500)
            ->call('reportBroken', $this->third->id)
            ->assertSee('clear it first');

        $this->assertNull($this->third->fresh()->meter_broken_reported_at);
    }

    // ---- wording ---------------------------------------------------------------

    /**
     * The labels this screen adds are counted (":n readings recorded"), and Arabic has six
     * plural forms: a fixed singular reads as broken Arabic to the collector holding the
     * phone, which is the whole audience for these strings.
     */
    public function test_the_new_counted_labels_use_the_arabic_plural_forms(): void
    {
        app()->setLocale('ar');

        $this->assertSame('تم تسجيل قراءتين', plural('portal.bulk_saved', 2));
        $this->assertSame('تم تسجيل 4 قراءات', plural('portal.bulk_saved', 4));
        $this->assertSame('تم تسجيل 11 قراءة', plural('portal.bulk_saved', 11));

        $this->assertSame('قراءتان مكتوبتان', plural('portal.typed_readings', 2));
        $this->assertSame('4 قراءات مكتوبة', plural('portal.typed_readings', 4));

        $this->assertSame('مشتركان باشتراك ثابت (بلا عدّاد)', plural('portal.bulk_flat_note', 2));
    }

    public function test_the_screen_is_readable_in_arabic(): void
    {
        // The portal follows the signed-in collector's own locale, so an Arabic screen is
        // an Arabic user — not a locale switched on the test runner.
        $arabic = User::create(['name' => 'سليم', 'email' => 'salim@x.com', 'password' => 'x', 'role' => 'collector', 'locale' => 'ar']);

        $this->actingAs($arabic)
            ->get(route('collector.route.readings', $this->route))
            ->assertOk()
            ->assertSee('قراءات المنطقة')
            ->assertSee('حفظ القراءات')
            ->assertSee('بلا قراءة');
    }
}
