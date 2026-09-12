<?php

namespace Tests\Feature;

use App\Admin\Forms\SubscriberForm;
use App\Admin\ResourceRegistry;
use App\Admin\Tables\SubscribersTable;
use App\Livewire\Admin\DataTable;
use App\Livewire\Admin\ResourceForm;
use App\Models\Bill;
use App\Models\Route;
use App\Admin\AdminNav;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The rebuilt panel — the only panel; the Filament one it replaced was deleted with it.
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

    // ---- the subscriber form SAVES ------------------------------------------

    /**
     * The half the render test cannot see: the form has to save.
     *
     * Every field the operator filled in passed — and the save was still refused, because the
     * flat-fee rule was validated while its whole section was hidden. The refusal landed on an
     * input that was not on the screen, so pressing Save looked exactly like pressing nothing at
     * all. That was every metered subscriber, on create AND on edit, which is most of the
     * network: the rule lives in FormDefinition (a field inside a hidden section contributes no
     * rules), and these are the saves it has to keep working.
     */
    public function test_a_metered_subscriber_is_created_from_the_form(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => SubscriberForm::class])
            ->set('state.route_id', $this->subscriber->route_id)
            ->set('state.name', 'سمير خليل')
            ->set('state.phone', '03 111222')
            ->set('state.type', Subscriber::TYPE_METERED)
            ->set('state.meter_no', '4102')
            ->set('state.amperage', 15)
            ->set('state.status', Subscriber::STATUS_ACTIVE)
            ->set('state.maintenance_mode', Subscriber::MAINT_AUTO)
            ->call('save')
            ->assertHasNoErrors();

        $created = Subscriber::query()->firstWhere('name', 'سمير خليل');

        $this->assertNotNull($created, 'the form saved a metered subscriber');
        $this->assertSame('4102', $created->meter_no);
        $this->assertSame(15, (int) $created->amperage);
        $this->assertNull($created->flat_fee, 'a metered house carries no flat fee');
        $this->assertSame($this->admin->id, (int) $created->created_by);

        // And the operator is taken somewhere, rather than left on a form that did nothing:
        // the new subscriber's own file, with the success line the layout reads from the flash.
        $this->assertStringContainsString(
            route('admin.subscribers.show', $created),
            (string) ($component->effects['redirect'] ?? ''),
        );
        $this->assertSame(__('t.created_ok'), session('status'));
    }

    public function test_a_flat_subscriber_is_created_from_the_form(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => SubscriberForm::class])
            ->set('state.route_id', $this->subscriber->route_id)
            ->set('state.name', 'ناديا خليل')
            ->set('state.type', Subscriber::TYPE_FLAT)
            ->set('state.flat_fee', 50)
            ->set('state.status', Subscriber::STATUS_ACTIVE)
            ->call('save')
            ->assertHasNoErrors();

        $created = Subscriber::query()->firstWhere('name', 'ناديا خليل');

        $this->assertNotNull($created, 'the form saved a flat subscriber');
        $this->assertSame(50.0, (float) $created->flat_fee);
        $this->assertNull($created->meter_no, 'a flat house has no meter to print');
        $this->assertNull($created->amperage);
    }

    public function test_a_metered_subscriber_is_saved_from_the_edit_form(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => SubscriberForm::class, 'record' => $this->subscriber->id])
            ->assertSet('state.meter_no', '2202')
            ->set('state.name', 'لينا مرعب حداد')
            ->set('state.amperage', 20)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('لينا مرعب حداد', $this->subscriber->fresh()->name);
        $this->assertSame(20, (int) $this->subscriber->fresh()->amperage);
    }

    /** The rule is not simply dropped: it refuses in the state where the field IS on the screen. */
    public function test_a_flat_subscriber_cannot_be_saved_without_its_fee(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ResourceForm::class, ['form' => SubscriberForm::class])
            ->set('state.route_id', $this->subscriber->route_id)
            ->set('state.name', 'بلا رسم')
            ->set('state.type', Subscriber::TYPE_FLAT)
            ->set('state.status', Subscriber::STATUS_ACTIVE)
            ->call('save')
            ->assertHasErrors('state.flat_fee');

        $this->assertNull(Subscriber::query()->firstWhere('name', 'بلا رسم'));
    }

    public function test_a_hidden_section_asks_for_nothing(): void
    {
        $form = new SubscriberForm;

        $metered = $form->rules(['type' => Subscriber::TYPE_METERED]);
        $this->assertArrayNotHasKey('state.flat_fee', $metered, 'the metered screen has no flat fee on it');
        $this->assertArrayHasKey('state.meter_no', $metered);
        $this->assertArrayHasKey('state.maintenance_mode', $metered);

        $flat = $form->rules(['type' => Subscriber::TYPE_FLAT]);
        $this->assertArrayHasKey('state.flat_fee', $flat);
        $this->assertArrayNotHasKey('state.amperage', $flat, 'the flat screen has no amperage on it');
        $this->assertArrayNotHasKey('state.maintenance_mode', $flat);
    }

    /**
     * The invariant behind the fix, for every form in the panel: a definition may only validate
     * what it shows. Written against the registry rather than one form, because the next section
     * that hides itself must fail here instead of in the office — where the symptom is a Save
     * button that does nothing, with the reason attached to a field nobody can see.
     */
    public function test_no_form_validates_a_field_it_does_not_show(): void
    {
        $definitions = collect(ResourceRegistry::all())
            ->pluck('form')
            ->filter()
            ->push(SubscriberForm::class)
            ->unique();

        $this->assertGreaterThan(3, $definitions->count(), 'the loop is reading the registry');

        foreach ($definitions as $class) {
            $form = new $class;
            $state = $form->defaults();

            $shown = array_map(fn (array $field): string => 'state.'.$field['key'], $form->visibleFields($state));

            foreach (array_keys($form->rules($state)) as $key) {
                $this->assertContains($key, $shown, "{$class} validates {$key} while it is off the screen.");
            }
        }
    }

    /**
     * A screen that has no route yet must be shown as unbuilt, not linked: rendering the nav
     * with a route() call for a missing route 500s EVERY page in the panel.
     *
     * Every screen now has a route, so the guard has flipped: nothing may be marked unbuilt,
     * and every entry must be a real link. This was the condition for moving /manage onto
     * /admin; now that the swap is done it guards against a screen losing its route.
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
