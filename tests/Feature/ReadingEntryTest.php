<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\ReadingEntry;
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
 * The office entering a street's readings.
 *
 * The same sheet the field uses, opened from the office: which street, which cycle, and one save
 * for every house that answered. What is pinned here is that the office cannot do anything the field
 * cannot — the rules live in the shared component and in BillingService, and this screen must be a
 * door to them, not a second implementation of them.
 */
class ReadingEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Route $route;

    private Route $quietRoute;

    private Subscriber $first;

    private Subscriber $second;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->period = BillingService::currentPeriod();

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@entry.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@entry.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'Ain El Remmaneh']);
        $this->quietRoute = Route::create(['name' => 'Tariq El Jdideh']);

        $this->first = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'محمد عيتاني', 'meter_no' => '1123',
            'type' => Subscriber::TYPE_METERED, 'amperage' => 10,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT, 'status' => Subscriber::STATUS_ACTIVE,
        ]);
        $this->second = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'سمير خليل', 'meter_no' => '2011',
            'type' => Subscriber::TYPE_METERED, 'amperage' => 5,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT, 'status' => Subscriber::STATUS_ACTIVE,
        ]);
    }

    // ---- the door -------------------------------------------------------------

    public function test_the_office_can_open_the_entry_screen(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.readings-entry'))
            ->assertOk()
            ->assertSee('اختر المنطقة');
    }

    public function test_a_collector_cannot_open_the_office_entry_screen(): void
    {
        $this->actingAs($this->collector)
            ->get(route('admin.readings-entry'))
            ->assertForbidden();
    }

    public function test_the_screen_is_reachable_from_the_readings_list_and_the_sidebar(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.readings.index'))->assertOk()
            ->assertSee(route('admin.readings-entry'));

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.readings-entry'));
    }

    // ---- choosing a street ----------------------------------------------------

    public function test_the_areas_are_listed_with_what_is_still_unread_most_unread_first(): void
    {
        // One of two houses read here; the other area has nothing metered at all.
        BillingService::enterMeteredReading($this->first, $this->period, 1400, $this->admin->id);

        $this->actingAs($this->admin);

        $component = Livewire::test(ReadingEntry::class);
        $routes = $component->instance()->routes();

        $this->assertSame('Ain El Remmaneh', $routes->first()['name'], 'the street with work left leads');
        $this->assertSame(2, $routes->first()['total']);
        $this->assertSame(1, $routes->first()['recorded']);
        $this->assertSame(1, $routes->first()['pending']);
        $this->assertSame(0, $routes->last()['pending']);

        $component->assertSee('Ain El Remmaneh')->assertSee('1 من 2');
    }

    public function test_choosing_an_area_opens_the_shared_sheet_for_it(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReadingEntry::class)
            ->assertSee('اختر المنطقة')
            ->call('selectRoute', $this->route->id)
            ->assertSet('routeId', $this->route->id)
            // The rows are the sheet's, with the same warnings and the same save button.
            ->assertSee('محمد عيتاني')
            ->assertSee('سمير خليل')
            ->assertSee('حفظ القراءات')
            ->assertSeeHtml('data-reading-input');

        // …and the choice is shareable: it lives in the URL.
        $this->actingAs($this->admin)
            ->get(route('admin.readings-entry', ['route_id' => $this->route->id]))
            ->assertOk()
            ->assertSeeHtml('data-reading-input');
    }

    public function test_the_area_can_be_changed_without_leaving_the_screen(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReadingEntry::class)
            ->call('selectRoute', $this->route->id)
            ->assertSeeHtml('data-reading-input')
            ->call('clearRoute')
            ->assertSet('routeId', null)
            ->assertSee('اختر المنطقة')
            ->assertDontSeeHtml('data-reading-input');
    }

    public function test_an_area_id_that_no_longer_exists_falls_back_to_the_picker(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReadingEntry::class, ['routeId' => 999])
            ->assertSet('routeId', null)
            ->assertSee('اختر المنطقة');
    }

    public function test_the_cycle_picker_covers_the_panel_window_and_defaults_to_this_month(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(ReadingEntry::class);

        $this->assertSame($this->period, $component->get('period'));
        $this->assertArrayHasKey($this->period, $component->instance()->periodOptions());
        $this->assertArrayHasKey(now()->startOfMonth()->subMonths(6)->format('Y-m'), $component->instance()->periodOptions());
    }

    // ---- entering readings ----------------------------------------------------

    public function test_the_office_records_a_whole_street_in_one_save(): void
    {
        $this->actingAs($this->admin);

        // The sheet is what saves; the entry screen is the door that renders it for a chosen street.
        Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->set('values.'.$this->first->id, 1400)
            ->set('values.'.$this->second->id, 1120)
            ->call('save')
            ->assertSet('savedCount', 2)
            ->assertSee('تم تسجيل قراءتين');

        $this->assertDatabaseHas('readings', [
            'subscriber_id' => $this->first->id, 'period' => $this->period, 'current_reading' => 1400,
        ]);
        $this->assertSame(2, Bill::where('period', $this->period)->count());
        // The office's user is on the record, which is how the collector filter answers "who
        // entered this".
        $this->assertSame($this->admin->id, Reading::where('subscriber_id', $this->first->id)->value('entered_by'));
    }

    public function test_the_cycle_chosen_on_the_entry_screen_reaches_the_sheet_and_the_reading(): void
    {
        $lastMonth = now()->startOfMonth()->subMonth()->format('Y-m');

        $this->actingAs($this->admin);

        // The screen hands the cycle down to the sheet…
        Livewire::test(ReadingEntry::class)
            ->set('period', $lastMonth)
            ->call('selectRoute', $this->route->id)
            ->assertSee(period_label_i18n($lastMonth));

        // …and a reading recorded through the sheet lands in that cycle, not in this one.
        Livewire::test(RouteReadingSheet::class, ['route' => $this->route, 'period' => $lastMonth])
            ->set('values.'.$this->first->id, 1350)
            ->call('save')
            ->assertSet('savedCount', 1);

        $this->assertDatabaseHas('readings', [
            'subscriber_id' => $this->first->id, 'period' => $lastMonth, 'current_reading' => 1350,
        ]);
        $this->assertSame(0, Reading::where('period', $this->period)->count());
    }

    public function test_the_shared_sheet_refuses_the_same_things_for_the_office(): void
    {
        // A house already read last cycle at 1500, and another whose meter was reported dead.
        $this->second->reportMeterBroken('العدّاد معطّل', $this->collector->id);
        BillingService::enterMeteredReading(
            $this->first, now()->startOfMonth()->subMonth()->format('Y-m'), 1500, $this->admin->id,
        );

        $this->actingAs($this->admin);

        $component = Livewire::test(RouteReadingSheet::class, ['route' => $this->route])
            ->set('values.'.$this->first->id, 1490)      // below the previous 1500
            ->set('values.'.$this->second->id, 3000);    // reported dead: not offered at all

        $component->call('save');

        $component->assertSet('savedCount', 0);
        $this->assertSame(0, Reading::where('period', $this->period)->count());
        $this->assertSame(1, count($component->get('failed')), 'the refused house is named');
    }

    /**
     * The sheet renders into two portals, and their subscriber pages are not interchangeable: a
     * collector sent to /admin is bounced out by middleware. The links are resolved per role, so a
     * row's link works for whoever is looking at it.
     */
    public function test_the_sheet_links_the_office_to_the_admin_subscriber_page(): void
    {
        $this->actingAs($this->admin);

        $sheet = Livewire::test(RouteReadingSheet::class, ['route' => $this->route]);

        $this->assertSame(route('admin.subscribers.show', $this->first->id), $sheet->instance()->subscriberUrl($this->first->id));
        $this->assertSame(route('admin.readings.index'), $sheet->instance()->backUrl());
        $sheet->assertSee(route('admin.subscribers.show', $this->first->id));
    }

    public function test_the_sheet_still_links_a_collector_to_the_collector_portal(): void
    {
        $this->actingAs($this->collector);

        $sheet = Livewire::test(RouteReadingSheet::class, ['route' => $this->route]);

        $this->assertSame(route('collector.subscriber.show', $this->first->id), $sheet->instance()->subscriberUrl($this->first->id));
        $this->assertSame(route('collector.route.show', $this->route), $sheet->instance()->backUrl());
    }
}
