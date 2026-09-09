<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
