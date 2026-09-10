<?php

namespace Tests\Feature;

use App\Filament\Pages\GenerateBills;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TariffAndNumbersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);
        $this->route = Route::create(['name' => 'Corniche']);
    }

    private function metered(string $name, float $maintenance = 15): Subscriber
    {
        $sub = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => $name,
            'meter_no' => 'M-'.$name,
            'type' => Subscriber::TYPE_METERED,
            'maintenance_fee' => $maintenance,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        Reading::create([
            'subscriber_id' => $sub->id, 'period' => '2026-06',
            'current_reading' => 1000, 'previous_reading' => 0, 'consumption' => 0,
        ]);

        return $sub;
    }

    public function test_cycle_tariff_wins_over_the_global_price(): void
    {
        Setting::set('kwh_price', '0.25');
        Tariff::create(['period' => '2026-09', 'kwh_price' => 0.54]);

        // The configured cycle uses the ministry tariff…
        $this->assertSame(0.54, BillingService::kwhPrice('2026-09'));
        $this->assertSame('tariff', BillingService::priceSource('2026-09'));

        // …a cycle without a row falls back to the global setting.
        $this->assertSame(0.25, BillingService::kwhPrice('2026-08'));
        $this->assertSame('setting', BillingService::priceSource('2026-08'));
        $this->assertSame(0.25, BillingService::kwhPrice());
    }

    public function test_bills_are_priced_with_their_own_cycle_tariff(): void
    {
        Setting::set('kwh_price', '0.25');
        Tariff::create(['period' => '2026-08', 'kwh_price' => 0.25]);
        Tariff::create(['period' => '2026-09', 'kwh_price' => 0.54]);

        $sub = $this->metered('Ali Hassan', 15);

        $august = BillingService::enterMeteredReading($sub, '2026-08', 1040, $this->admin->id)['bill'];
        $september = BillingService::enterMeteredReading($sub, '2026-09', 1080, $this->admin->id)['bill'];

        // 40 kWh x 0.25 = 10 + 15 maintenance
        $this->assertSame(0.25, (float) $august->kwh_price);
        $this->assertSame(25.0, (float) $august->total);
        // 40 kWh x 0.54 = 21.6 + 15 maintenance
        $this->assertSame(0.54, (float) $september->kwh_price);
        $this->assertSame(36.6, (float) $september->total);

        // The billing run prices by cycle too.
        $other = $this->metered('Sami Nour');
        Reading::create([
            'subscriber_id' => $other->id, 'period' => '2026-09',
            'current_reading' => 1100, 'previous_reading' => 1000, 'consumption' => 100,
        ]);
        $run = BillingService::periodRun('2026-09', null, true, $this->admin->id);
        $this->assertSame(1, $run['created']);
        $this->assertSame(0.54, (float) $other->bills()->where('period', '2026-09')->value('kwh_price'));
    }

    public function test_bills_and_payments_get_readable_document_numbers(): void
    {
        $sub = $this->metered('Ali Hassan', 15);
        $bill = BillingService::enterMeteredReading($sub, '2026-09', 1040, $this->admin->id)['bill'];

        $this->assertSame('INV-2026-09-'.str_pad((string) $sub->id, 4, '0', STR_PAD_LEFT), $bill->number);

        $payment = BillingService::collect($sub, 10, $this->admin->id, null, '2026-09')['payment'];
        $this->assertSame('REC-2026-09-'.str_pad((string) $payment->id, 4, '0', STR_PAD_LEFT), $payment->number);

        // Every created row carries one (no nulls to search around).
        $this->assertSame(0, Bill::whereNull('number')->count());
        $this->assertSame(1, Bill::whereNotNull('number')->count());
        $this->assertNotNull($bill->fresh()->number);
        $this->assertSame(0, Payment::whereNotNull('number')->where('id', '!=', $payment->id)->count());
    }

    public function test_reading_progress_reports_coverage_per_route(): void
    {
        $ready = $this->metered('Ready One');
        $this->metered('Missing One');
        Reading::create([
            'subscriber_id' => $ready->id, 'period' => '2026-09',
            'current_reading' => 1100, 'previous_reading' => 1000, 'consumption' => 100,
        ]);

        // Flat subscribers never hold up metering coverage.
        Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'Flat Guy',
            'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 60,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
        // An inactive metered subscriber is not expected to be read either.
        $this->metered('Inactive Guy');
        Subscriber::where('name', 'Inactive Guy')->update(['status' => Subscriber::STATUS_INACTIVE]);

        $progress = BillingService::readingProgress('2026-09');

        $this->assertSame(2, $progress['total']);
        $this->assertSame(1, $progress['recorded']);
        $this->assertSame(1, $progress['pending']);
        $this->assertSame('Corniche', $progress['routes'][0]['name']);
        $this->assertSame(2, $progress['routes'][0]['total']);
        $this->assertSame(1, $progress['routes'][0]['pending']);

        // Scoped by route, and a fully-read cycle reports zero pending.
        $this->assertSame(1, BillingService::readingProgress('2026-09', $this->route->id)['pending']);
        $this->assertSame(0, BillingService::readingProgress('2026-06')['pending']);
    }

    public function test_run_page_shows_coverage_tariff_and_the_span_column(): void
    {
        Tariff::create(['period' => '2026-09', 'kwh_price' => 0.54, 'note' => 'MoEW September']);
        $ready = $this->metered('Ready One');
        $this->metered('Missing One');
        Reading::create([
            'subscriber_id' => $ready->id, 'period' => '2026-09',
            'current_reading' => 1100, 'previous_reading' => 1000, 'consumption' => 100,
        ]);

        $this->actingAs($this->admin)->get('/admin/generate-bills')->assertOk();

        $component = Livewire::actingAs($this->admin)
            ->test(GenerateBills::class)
            ->set('data.period', '2026-09')
            ->assertOk()
            ->assertSee('Cycle meter-reading coverage')
            ->assertSee('1 readings missing')          // the amber gate
            ->assertSee('1 of 2 recorded')
            ->assertSee('0.54')                        // cycle tariff chip
            ->assertSee('cycle tariff')
            ->assertSee('1,000 → 1,100');              // reading span column

        $this->assertSame(1, $component->instance()->progress()['pending']);
        $this->assertFalse($component->instance()->tariff()['from_tariff'] === false);

        // Generating only bills the subscriber that has a reading.
        $component->call('generate')->assertHasNoErrors();
        $this->assertSame(1, Bill::where('period', '2026-09')->count());
        $this->assertSame(100.0 * 0.54 + 15.0, (float) Bill::where('period', '2026-09')->value('total'));
    }

    public function test_printed_documents_show_numbers_and_the_reading_span(): void
    {
        Tariff::create(['period' => '2026-09', 'kwh_price' => 0.54]);
        $sub = $this->metered('Ali Hassan', 15);
        $bill = BillingService::enterMeteredReading($sub, '2026-09', 1040, $this->admin->id)['bill'];
        $payment = BillingService::collect($sub, 10, $this->admin->id, null, '2026-09')['payment'];

        $this->actingAs($this->admin)
            ->get(route('print.bill', ['bill' => $bill->id]))
            ->assertOk()
            ->assertSee($bill->number)
            ->assertSee('1,000')
            ->assertSee('1,040')
            ->assertSee('0.54');

        $this->actingAs($this->admin)
            ->get(route('print.receipt', ['payment' => $payment->id]))
            ->assertOk()
            ->assertSee($payment->number);
    }

    public function test_tariff_resource_pages_render_and_are_admin_only(): void
    {
        $tariff = Tariff::create(['period' => '2026-10', 'kwh_price' => 0.6, 'created_by' => $this->admin->id]);
        $collector = User::create(['name' => 'C', 'email' => 'c@x.com', 'password' => 'x', 'role' => 'collector', 'locale' => 'en']);

        foreach ([
            '/admin/tariffs',
            '/admin/tariffs/create',
            '/admin/tariffs/'.$tariff->id.'/edit',
        ] as $path) {
            $this->actingAs($this->admin)->get($path)->assertOk($path.' failed to render');
        }

        $this->actingAs($collector)->get('/admin/tariffs')->assertForbidden();
    }
}
