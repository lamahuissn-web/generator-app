<?php

namespace Tests\Feature;

use App\Admin\Tables\SubscribersTable;
use App\Livewire\Admin\DataTable;
use App\Models\Bill;
use App\Models\Route;
use App\Admin\AdminNav;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The rebuilt panel (formerly Filament).
 *
 * Covers the shell, the door, the generic table and the screens built so far. Every entry in
 * the nav is asserted to resolve to a real route, so a screen cannot be silently missing.
 */
class AdminPanelRebuildTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Subscriber $subscriber;

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

        $route = Route::create(['name' => 'Ain El Remmaneh']);
        $this->subscriber = Subscriber::create([
            'route_id' => $route->id,
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

    // ---- the door ---------------------------------------------------------

    public function test_guest_is_sent_to_the_panel_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_panel_login_page_renders(): void
    {
        $this->get(route('admin.login'))->assertOk()->assertSee('name="password"', false);
    }

    public function test_admin_can_sign_in_and_reaches_the_dashboard(): void
    {
        $this->post(route('admin.login.attempt'), [
            'email' => 'admin@panel.test',
            'password' => 'secret',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($this->admin);
    }

    /** One users table, two doors: collector credentials must not open the panel. */
    public function test_collector_credentials_are_refused_at_the_panel_login(): void
    {
        $this->post(route('admin.login.attempt'), [
            'email' => 'jad@panel.test',
            'password' => 'secret',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_collector_cannot_open_the_panel(): void
    {
        $this->actingAs($this->collector)->get(route('admin.dashboard'))->assertForbidden();
    }

    // ---- screens ----------------------------------------------------------

    public function test_dashboard_renders_the_operator_figures(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Outstanding debt')
            ->assertSee('$36.50');
    }

    public function test_subscriber_list_renders_rows(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.subscribers.index'))
            ->assertOk()
            ->assertSee('لينا مرعب')
            ->assertSee('$36.50');
    }

    public function test_subscriber_profile_renders_with_its_tabs(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.subscribers.show', $this->subscriber))
            ->assertOk()
            ->assertSee('لينا مرعب')
            ->assertSee('$36.50');

        $this->actingAs($this->admin)
            ->get(route('admin.subscribers.show', ['subscriber' => $this->subscriber, 'tab' => 'payments']))
            ->assertOk()
            ->assertSee('لينا مرعب');
    }

    public function test_subscriber_create_and_edit_forms_render(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.subscribers.create'))
            ->assertOk()
            ->assertSee('wire:model="state.name"', false);

        $this->actingAs($this->admin)
            ->get(route('admin.subscribers.edit', $this->subscriber))
            ->assertOk()
            ->assertSee('لينا مرعب');
    }

    /**
     * A screen that has no route yet must be shown as unbuilt, not linked: rendering the nav
     * with a route() call for a missing route 500s EVERY page in the panel.
     *
     * Every screen now has a route, so the guard has flipped: nothing may be marked unbuilt,
     * and every entry must be a real link. That is the condition for swapping /manage onto
     * /admin — if this fails, the panel is not at parity and the swap would break the nav.
     */
    public function test_every_nav_item_is_linked(): void
    {
        $items = collect(AdminNav::groups())->flatMap(fn (array $group) => $group['items']);

        $this->assertNotEmpty($items);

        $unbuilt = $items->reject(fn (array $item) => AdminNav::exists($item))->pluck('label');
        $this->assertTrue(
            $unbuilt->isEmpty(),
            'Screens still have no route, so the nav renders them as unbuilt: '.$unbuilt->implode(', '),
        );

        $page = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        foreach ($items as $item) {
            // Escaping ON: a label like "Profit & Loss" is rendered as `Profit &amp; Loss`,
            // and searching the raw string would fail for the wrong reason.
            $page->assertSee($item['label']);
            $page->assertSee('href="'.route($item['route']).'"', false);
        }

        // …and nothing is advertised as unbuilt any more.
        $page->assertDontSee('soon');
    }

    // ---- the generic table ------------------------------------------------

    public function test_table_searches_filters_and_sorts(): void
    {
        $other = Subscriber::create([
            'route_id' => $this->subscriber->route_id,
            'name' => 'Ali Hassan',
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60,
            'status' => Subscriber::STATUS_INACTIVE,
            'created_by' => $this->admin->id,
        ]);

        $table = Livewire::test(DataTable::class, ['table' => SubscribersTable::class]);

        // Search matches a name…
        $table->set('search', 'لينا')->assertSee('لينا مرعب')->assertDontSee('Ali Hassan');

        // …and a meter number, which is how a collector looks a household up.
        $table->set('search', '2202')->assertSee('لينا مرعب')->assertDontSee('Ali Hassan');

        $table->set('search', '')->set('filters.status', '0')->assertSee('Ali Hassan')->assertDontSee('لينا مرعب');

        // The outstanding figure is a SUM over bills, so it sorts by raw SQL, not a column.
        $table->set('filters.status', '')->call('sortBy', 'outstanding')->assertOk();
        $this->assertSame('outstanding', $table->get('sort'));
    }

    /**
     * A badge column is an EXCEPTION flag: the ordinary row must not paint a blank chip.
     * The meter-report column reads empty for a working meter, and the first cut rendered
     * a red pill with no text on every healthy subscriber.
     */
    public function test_exception_badge_is_absent_when_there_is_nothing_to_report(): void
    {
        $html = Livewire::test(DataTable::class, ['table' => SubscribersTable::class])->html();

        $this->assertStringNotContainsString('kt-badge-destructive', $html);

        $this->subscriber->reportMeterBroken('meter is dead', $this->admin->id);

        $html = Livewire::test(DataTable::class, ['table' => SubscribersTable::class])->html();
        $this->assertStringContainsString('kt-badge-destructive', $html);
        $this->assertStringContainsString(__('t.meter_broken_badge'), $html);
    }

    /** Deleting a subscriber with billing history warns about the blast radius first. */
    public function test_delete_warning_names_the_history_it_would_take_with_it(): void
    {
        $warning = (new SubscribersTable)->deleteWarning($this->subscriber);

        $this->assertSame(__('a.del_sub_history'), $warning);
    }
}
