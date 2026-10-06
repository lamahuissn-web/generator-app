<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use App\Services\BillingService;
use Database\Seeders\CleanSeeder;
use Database\Seeders\DemoSeeder;
use App\Admin\AdminNav;
use App\Admin\ResourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The hand-over baseline: `migrate:fresh --seed` must produce an EMPTY program that
 * still works, and `app:reset-data` must be able to empty a used one without
 * touching the accounts.
 */
class CleanSlateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeAtTheSeptemberCycle();
    }

    private const DOMAIN_MODELS = [
        Subscriber::class,
        Reading::class,
        Bill::class,
        Payment::class,
        Expense::class,
        CashHandover::class,
        Tariff::class,
        Route::class,
    ];

    private function assertProgramIsEmpty(): void
    {
        foreach (self::DOMAIN_MODELS as $model) {
            $this->assertSame(0, $model::count(), $model.' should be empty');
        }
    }

    public function test_clean_seeder_leaves_an_empty_program_with_a_working_admin(): void
    {
        $this->seed(CleanSeeder::class);

        $this->assertProgramIsEmpty();
        $this->assertSame(1, User::count());

        $admin = User::firstOrFail();
        $this->assertSame('admin@example.com', $admin->email);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('admin123', $admin->password), 'seeded admin password must work');

        // Billing math still has a price before any tariff/company config exists.
        $this->assertSame(0.25, BillingService::kwhPrice('2026-09'));
        $this->assertSame('setting', BillingService::priceSource('2026-09'));
        $this->assertSame(15.0, BillingService::defaultMaintenanceFee());
        $this->assertSame('', (string) Setting::get('company_name'));

        // Re-seeding is safe (idempotent, no duplicate admin).
        $this->seed(CleanSeeder::class);
        $this->assertSame(1, User::count());
    }

    public function test_the_seeded_admin_can_log_in_through_the_panel_form(): void
    {
        $this->seed(CleanSeeder::class);

        // The door, through HTTP: the form posts to the panel's own login route.
        $this->post('/admin/login', [
            'identity' => 'admin@example.com',
            'password' => 'admin123',
        ])->assertRedirect(route('admin.dashboard'))->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs(User::firstOrFail());
    }

    /** The door also refuses the wrong password, and a collector's account. */
    public function test_the_panel_login_refuses_bad_credentials(): void
    {
        $this->seed(CleanSeeder::class);

        User::create([
            'name' => 'Collector', 'email' => 'c@example.com',
            'password' => 'collector123', 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->post('/admin/login', ['identity' => 'admin@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('identity');
        $this->assertGuest();

        $this->post('/admin/login', ['identity' => 'c@example.com', 'password' => 'collector123'])
            ->assertSessionHasErrors('identity');
        $this->assertGuest();
    }

    public function test_every_admin_page_renders_on_an_empty_database(): void
    {
        $this->seed(CleanSeeder::class);
        $this->actingAs(User::firstOrFail());

        // Every screen the panel advertises, derived from the nav and the registry rather
        // than hand-listed: a screen added later is covered without touching this test, and
        // one that is listed but unbuildable cannot be quietly skipped.
        $pages = ['/admin'];

        foreach (AdminNav::pages() as $page) {
            $pages[] = route($page['route']);
        }

        foreach (ResourceRegistry::all() as $key => $resource) {
            $pages[] = route('admin.'.$key.'.index');

            if (! empty($resource['form'])) {
                $pages[] = route('admin.'.$key.'.create');
            }
        }

        $this->assertGreaterThan(20, count($pages), 'The derived screen list looks too small.');

        foreach ($pages as $page) {
            $this->get($page)->assertOk($page.' failed to render on an empty database');
        }

        // The collector portal still stands up with no collectors seeded; a logged-in
        // admin is bounced away from its login form (that is the intended guard).
        $this->get('/collector/login')->assertRedirect();
    }

    public function test_reset_data_empties_a_used_program_and_keeps_the_accounts(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertGreaterThan(0, Subscriber::count());
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $collectorCount = User::where('role', 'collector')->count();
        $this->assertGreaterThan(0, $collectorCount);

        $this->artisan('app:reset-data --force')->assertSuccessful();

        $this->assertProgramIsEmpty();
        $this->assertSame(1 + $collectorCount, User::count(), 'accounts must survive the wipe');
        $this->assertTrue(User::where('email', 'admin@example.com')->exists());
        $this->assertTrue(Hash::check('admin123', $admin->fresh()->password), 'the admin password must still work');

        // Settings survive by default, so the operator keeps their company identity.
        $this->assertSame('مولّد البرج — أبو حسن', Setting::get('company_name'));

        // And the program is usable right away.
        $this->actingAs($admin->fresh())->get('/admin')->assertOk();
        $this->actingAs($admin->fresh())->get('/admin/generate-bills')->assertOk();
    }

    public function test_reset_data_can_also_reset_the_settings(): void
    {
        $this->seed(DemoSeeder::class);

        $this->artisan('app:reset-data --force --settings')->assertSuccessful();

        $this->assertProgramIsEmpty();
        $this->assertSame('', (string) Setting::get('company_name'));
        $this->assertSame(0.25, BillingService::kwhPrice('2026-09'));
        $this->assertGreaterThan(0, User::count());
    }

    public function test_demo_seeder_is_still_available_on_demand(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(12, Subscriber::count());
        $this->assertGreaterThan(0, Bill::count());
        $this->assertGreaterThan(0, Payment::count());
        $this->assertGreaterThan(0, Tariff::count());
        $this->assertSame(3, User::count());
    }

    public function test_a_fresh_program_can_record_its_first_subscriber_and_bill(): void
    {
        $this->seed(CleanSeeder::class);
        $admin = User::firstOrFail();
        $this->actingAs($admin);

        // The operator's first real workflow must work from zero: route → subscriber → reading → bill.
        $route = Route::create(['name' => 'الضاحية']);
        $subscriber = Subscriber::create([
            'route_id' => $route->id,
            'name' => 'أول مشترك',
            'phone' => '03 000000',
            'meter_no' => '9001',
            'type' => Subscriber::TYPE_METERED,
            'amperage' => 10,
            'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);

        Reading::create([
            'subscriber_id' => $subscriber->id, 'period' => '2026-08',
            'current_reading' => 1000, 'previous_reading' => 0, 'consumption' => 0,
        ]);
        $result = BillingService::enterMeteredReading($subscriber, '2026-09', 1040, $admin->id);

        $this->assertSame(10.0, (float) $result['bill']->usage_amount);   // 40 kWh × $0.25
        $this->assertSame(25.0, (float) $result['bill']->total);   // + $15 maintenance
        $this->assertSame('INV-2026-09-0001', $result['bill']->number);

        $payment = BillingService::collect($subscriber, 25, $admin->id, null, '2026-09', Payment::METHOD_CASH_USD)['payment'];

        $this->assertSame('REC-2026-09-0001', $payment->number);
        $this->assertSame(0.0, $subscriber->fresh()->outstanding());

        // The operator sees the whole first cycle in the panel.
        $this->get('/admin/payments')->assertOk()->assertSee('REC-2026-09-0001');
        $this->get('/admin/subscribers/'.$subscriber->id)->assertOk()->assertSee('أول مشترك');
        $this->get(route('print.receipt', ['payment' => $payment->id, 'size' => 'a4']))->assertOk();
    }
}
