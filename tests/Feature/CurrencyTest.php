<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\Settings;
use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lira: the one rate, and where it shows.
 *
 * The app bills in dollars and the street pays in lira. Until now the only trace of that was a
 * payment METHOD ("نقداً ليرة") with a dollar amount next to it: the collector converted in his
 * head, the lira figure was never written down, and the office could not answer "أكم قيمتها ليرة
 * اليوم؟" for a standing debt.
 *
 * These tests pin the decisions that were made rather than left implicit:
 *   - no rate configured means NO lira anywhere (not a zero that looks payable);
 *   - the conversions are two on purpose: exact() is money, display() is what a person writes
 *     down, rounded to the thousand the street uses;
 *   - the figure shown beside a bill is TODAY's lira, and its label says so.
 */
class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 89500;

    private User $admin;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@currency.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);

        $route = Route::create(['name' => 'Ain El Remmaneh']);
        $this->subscriber = Subscriber::create([
            'route_id' => $route->id, 'name' => 'محمد عيتاني', 'meter_no' => '1123',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);

        $reading = Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-08',
            'previous_reading' => 1200, 'current_reading' => 1270, 'consumption' => 70,
            'entered_by' => $this->admin->id,
        ]);

        Bill::create([
            'number' => 'INV-2026-08-9001', 'subscriber_id' => $this->subscriber->id,
            'period' => '2026-08', 'type' => Subscriber::TYPE_METERED,
            'reading_id' => $reading->id, 'consumption' => 70, 'usage_amount' => 110.0, 'total' => 110.0,
        ]);
    }

    // ---- the rate itself ---------------------------------------------------------

    public function test_without_a_rate_there_is_no_lira_anywhere(): void
    {
        $this->assertSame(0.0, Currency::rate());
        $this->assertFalse(Currency::isConfigured());
        $this->assertNull(lbp(110));
        $this->assertNull(lbp_ltr(110));

        $this->actingAs($this->admin)
            ->get('/admin/subscribers/'.$this->subscriber->id)
            ->assertOk()
            ->assertSee('$110.00')
            ->assertDontSee('LBP')
            // The page does not explain a conversion it is not making.
            ->assertDontSee('بسعر اليوم');
    }

    public function test_the_rate_is_set_on_the_settings_screen_and_read_by_the_panel(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Settings::class)
            ->set('state.lbp_rate', (string) self::RATE)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame((string) self::RATE, Setting::get('lbp_rate'));
        $this->assertTrue(Currency::isConfigured());
        $this->assertSame((float) self::RATE, Currency::rate());
    }

    public function test_a_negative_rate_is_refused(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Settings::class)
            ->set('state.lbp_rate', '-5')
            ->call('save')
            ->assertHasErrors('state.lbp_rate');

        // Nothing was written: a rejected form must not half-save.
        $this->assertNull(Setting::get('lbp_rate'));
    }

    // ---- the two conversions -------------------------------------------------------

    public function test_money_is_exact_and_a_caption_is_rounded(): void
    {
        Setting::set(Currency::RATE_KEY, (string) self::RATE);

        // 110 × 89,500 is already a round number…
        $this->assertSame(9845000.0, Currency::exact(110));
        $this->assertSame('LBP 9,845,000', Currency::label(110));

        // …while a receipt's cents land on the thousand a person would say out loud. Both are
        // kept: a stored amount must stay exact, a printed one must stay readable.
        $this->assertSame(110085.0, Currency::exact(1.23));
        $this->assertSame(110000.0, Currency::display(1.23));
        $this->assertSame('LBP 110,000', Currency::label(1.23));
    }

    // ---- where it shows ---------------------------------------------------------------

    public function test_the_file_shows_the_standing_debt_in_lira_next_to_the_dollars(): void
    {
        Setting::set(Currency::RATE_KEY, (string) self::RATE);

        $this->actingAs($this->admin)
            ->get('/admin/subscribers/'.$this->subscriber->id)
            ->assertOk()
            ->assertSee('$110.00')
            ->assertSee('LBP 9,845,000')
            // …and says WHICH lira that is, because a closed month's invoice is worth a different
            // lira than the one the street is being asked for today.
            ->assertSee('بالليرة (بسعر اليوم)')
            ->assertSee('سعر الصرف');
    }

    public function test_the_lira_column_empties_itself_when_the_rate_is_gone(): void
    {
        Setting::set(Currency::RATE_KEY, (string) self::RATE);
        $url = '/admin/subscribers/'.$this->subscriber->id;

        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('LBP 9,845,000');

        // The office switches the rate off (bills in dollars only): the column stops being filled
        // in rather than printing zeros for every invoice.
        Setting::set(Currency::RATE_KEY, '0');

        $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSee('LBP');
    }
}
