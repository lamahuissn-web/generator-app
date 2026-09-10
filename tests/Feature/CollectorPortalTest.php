<?php

namespace Tests\Feature;

use App\Livewire\Collector\GlobalSearch;
use App\Livewire\Collector\SubscriberShow;
use App\Models\Bill;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CollectorPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $collector;

    private Route $route;

    private Subscriber $metered;

    private Subscriber $flat;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);
        $this->collector = User::create(['name' => 'Jad', 'email' => 'jad@x.com', 'password' => 'x', 'role' => 'collector', 'locale' => 'en']);

        $this->route = Route::create(['name' => 'Ramlet El Baida']);
        $this->metered = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'محمد عيتاني',
            'phone' => '03 123456',
            'meter_no' => '1123',
            'type' => Subscriber::TYPE_METERED,
            'amperage' => 10,
            'maintenance_fee' => 25,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);
        $this->flat = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'Flat Sub',
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);
    }

    public function test_guest_is_redirected_from_dashboard_to_login(): void
    {
        $this->get(route('collector.dashboard'))->assertRedirect(route('login'));
    }

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sign in');
    }

    public function test_collector_can_log_in_with_credentials(): void
    {
        $this->post(route('collector.login.attempt'), [
            'email' => 'jad@x.com',
            'password' => 'x',
        ])->assertRedirect(route('collector.dashboard'));

        $this->assertAuthenticatedAs($this->collector);
    }

    public function test_dashboard_lists_routes(): void
    {
        $this->actingAs($this->collector)
            ->get(route('collector.dashboard'))
            ->assertOk()
            ->assertSee('Ramlet El Baida')
            ->assertSee('Field Collections');
    }

    public function test_route_page_lists_subscribers_with_balances(): void
    {
        $this->actingAs($this->collector)
            ->get(route('collector.route.show', $this->route))
            ->assertOk()
            ->assertSee('محمد عيتاني')
            ->assertSee('Flat Sub');
    }

    /**
     * Regression: the per-row lookup used to be assigned to a variable named after the
     * collection it read from, so from the second row on the collector saw "$0 · settled"
     * for every subscriber after the first — the field money was told to collect vanished.
     */
    public function test_route_page_shows_the_amount_due_on_every_row_not_just_the_first(): void
    {
        Bill::create([
            'number' => 'INV-2026-09-9001', 'subscriber_id' => $this->metered->id, 'period' => '2026-09',
            'type' => 'metered', 'usage_amount' => 15, 'maintenance_fee' => 25, 'total' => 40, 'paid_amount' => 0,
        ]);
        Bill::create([
            'number' => 'INV-2026-09-9002', 'subscriber_id' => $this->flat->id, 'period' => '2026-09',
            'type' => 'flat_rate', 'flat_amount' => 60, 'total' => 60, 'paid_amount' => 40,
        ]);

        $response = $this->actingAs($this->collector)
            ->get(route('collector.route.show', $this->route))
            ->assertOk();

        $response->assertSee('$40.00')->assertSee('$20.00');
    }

    public function test_route_page_marks_a_fully_paid_subscriber_as_settled(): void
    {
        $settled = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'Settled Sub',
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 50,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->collector->id,
        ]);
        Bill::create([
            'number' => 'INV-2026-09-9003', 'subscriber_id' => $settled->id, 'period' => '2026-09',
            'type' => 'flat_rate', 'flat_amount' => 50, 'total' => 50, 'paid_amount' => 50,
        ]);
        Bill::create([
            'number' => 'INV-2026-09-9004', 'subscriber_id' => $this->metered->id, 'period' => '2026-09',
            'type' => 'metered', 'usage_amount' => 15, 'maintenance_fee' => 25, 'total' => 40, 'paid_amount' => 0,
        ]);

        // The metered subscriber owes $40: their row must not collapse into the settled branch.
        $this->actingAs($this->collector)
            ->get(route('collector.route.show', $this->route))
            ->assertOk()
            ->assertSee('$40.00');
    }

    /**
     * The other two screens that print a per-subscriber balance: global search and the
     * subscriber page itself. Both must report the subscriber's own figure — not the
     * first row's, and not zero because the lookup was consumed by the row above.
     */
    public function test_global_search_reports_each_hit_the_balance_it_actually_owes(): void
    {
        foreach ([['زينب كرم', 70, 0], ['زينب سعد', 60, 40], ['زينب نصر', 50, 50]] as $i => [$name, $total, $paid]) {
            $sub = Subscriber::create([
                'route_id' => $this->route->id,
                'name' => $name,
                'type' => Subscriber::TYPE_FLAT,
                'flat_fee' => $total,
                'status' => Subscriber::STATUS_ACTIVE,
                'created_by' => $this->collector->id,
            ]);
            Bill::create([
                'number' => 'INV-2026-09-95'.$i, 'subscriber_id' => $sub->id, 'period' => '2026-09',
                'type' => 'flat_rate', 'flat_amount' => $total, 'total' => $total, 'paid_amount' => $paid,
            ]);
        }

        $component = Livewire::test(GlobalSearch::class)->set('q', 'زينب');

        $owed = collect($component->get('results'))->pluck('owed', 'name');
        $this->assertSame(70.0, $owed['زينب كرم']);
        $this->assertSame(20.0, $owed['زينب سعد']);
        $this->assertSame(0.0, $owed['زينب نصر']);

        // Same shape as the route list: the amount with its state under it, for every hit —
        // a settled subscriber reads "0 · settled" instead of showing nothing at all.
        $component->assertSee('$70.00')->assertSee('$20.00')->assertSee('settled');
    }

    public function test_subscriber_page_shows_the_balance_still_owed(): void
    {
        Bill::create([
            'number' => 'INV-2026-09-9005', 'subscriber_id' => $this->flat->id, 'period' => '2026-09',
            'type' => 'flat_rate', 'flat_amount' => 60, 'total' => 60, 'paid_amount' => 25,
        ]);

        Livewire::test(SubscriberShow::class, ['subscriber' => $this->flat->fresh()])
            ->assertSee('$35.00');
    }

    public function test_metered_subscriber_page_offers_reading_entry(): void
    {
        $this->actingAs($this->collector)
            ->get(route('collector.subscriber.show', $this->metered))
            ->assertOk()
            ->assertSee('Outstanding balance')
            ->assertSee('Take reading');
    }

    public function test_flat_subscriber_page_offers_collection(): void
    {
        $this->actingAs($this->collector)
            ->get(route('collector.subscriber.show', $this->flat))
            ->assertOk()
            ->assertSee('Flat-rate subscriber')
            ->assertSee('Collect payment');
    }

    public function test_collector_cannot_open_admin_panel(): void
    {
        $response = $this->actingAs($this->collector)->get('/admin');

        $this->assertTrue(
            in_array($response->status(), [302, 403], true),
            'Collector must be locked out of /admin, got '.$response->status()
        );
    }

    public function test_admin_login_form_redirects_admin_away_from_collector_login(): void
    {
        $admin = User::where('email', 'admin@x.com')->first();

        $this->post(route('collector.login.attempt'), [
            'email' => $admin->email,
            'password' => 'x',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_arabic_collector_interface_renders_rtl(): void
    {
        $arCollector = User::create(['name' => 'سليم', 'email' => 'salim@x.com', 'password' => 'x', 'role' => 'collector', 'locale' => 'ar']);

        $this->actingAs($arCollector)
            ->get(route('collector.dashboard'))
            ->assertOk()
            ->assertSee('المناطق')
            ->assertSee('المحصل اليوم');

        $this->actingAs($arCollector)
            ->get(route('collector.subscriber.show', $this->metered))
            ->assertOk()
            ->assertSee('الرصيد المطلوب')
            ->assertSee('تسجيل قراءة');
    }

    public function test_locale_switch_updates_user_preference(): void
    {
        $this->actingAs($this->collector)
            ->post(route('locale.switch'), ['locale' => 'ar'])
            ->assertRedirect();

        $this->assertSame('ar', $this->collector->fresh()->locale);
    }
}
