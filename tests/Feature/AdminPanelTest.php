<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel as a whole: the shell, the figures, the language, and the one arithmetic
 * invariant the whole product rests on.
 *
 * The per-screen renders are covered by AdminPanelRebuildTest / AdminResourcesTest /
 * AdminPagesTest, which derive their screen list from the nav and the registry. What stays
 * here is what no single screen owns.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);

        // Minimal network so lists and widgets have data to aggregate.
        $route = Route::create(['name' => 'Demo Route']);
        $sub = Subscriber::create([
            'route_id' => $route->id,
            'name' => 'Demo Sub',
            'phone' => '03 999888',
            'meter_no' => 'D1',
            'type' => Subscriber::TYPE_METERED,
            'amperage' => 10,
            'maintenance_fee' => 25,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        Reading::create([
            'subscriber_id' => $sub->id,
            'period' => now()->subMonth()->format('Y-m'),
            'current_reading' => 100,
            'previous_reading' => 0,
            'consumption' => 100,
            'entered_by' => $this->admin->id,
        ]);
        BillingService::enterMeteredReading($sub, now()->format('Y-m'), 130, $this->admin->id);
        BillingService::collect($sub, 10, $this->admin->id, null, now()->format('Y-m'));

        // Expense data so the finance screens render their populated (non-empty) state.
        Expense::create([
            'category' => Expense::CAT_FUEL,
            'amount' => 40,
            'quantity' => 100,
            'expense_date' => now()->toDateString(),
            'description' => 'Test diesel',
            'entered_by' => $this->admin->id,
        ]);
    }

    public function test_admin_can_open_dashboard_with_widgets(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Invoiced');
    }

    /**
     * Whatever is billed minus whatever was collected is what is still owed.
     *
     * This is the product's whole arithmetic. If it ever fails, every figure in every report
     * is suspect — so it is asserted on the model the reports are all derived from.
     */
    public function test_fresh_data_leaves_audit_consistency(): void
    {
        $sub = Subscriber::first();
        $sumBills = (float) $sub->bills()->sum('total');
        $sumPayments = (float) Payment::where('subscriber_id', $sub->id)->sum('amount');

        $this->assertEqualsWithDelta($sumBills - $sumPayments, $sub->outstanding(), 0.01);
    }

    /**
     * The panel follows the signed-in user's language.
     *
     * The layout's AR/EN button writes that preference and the 'locale' middleware reads it
     * back; without the middleware the panel sits on config('app.locale') and the button is
     * decorative.
     */
    public function test_the_panel_speaks_the_language_the_account_asks_for(): void
    {
        $english = User::create(['name' => 'English', 'email' => 'en@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);
        $arabic = User::create(['name' => 'مدير', 'email' => 'ar@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'ar']);

        // A label that differs between the two files, so the assertion cannot pass by accident.
        $this->assertNotSame(__('mdl.subscribers', [], 'en'), __('mdl.subscribers', [], 'ar'));

        $this->actingAs($english)->get('/admin/subscribers')->assertOk()->assertSee(__('mdl.subscribers', [], 'en'));
        $this->actingAs($arabic)->get('/admin/subscribers')->assertOk()->assertSee(__('mdl.subscribers', [], 'ar'));
    }

    /**
     * Numbers stay Western (0-9) even when the UI is Arabic.
     *
     * A product rule, not a preference: Arabic-Indic digits would break receipts, meter
     * numbers and the money columns the operator reads out over the phone.
     */
    public function test_arabic_ui_still_uses_western_digits(): void
    {
        $arabic = User::create(['name' => 'مدير', 'email' => 'ar2@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'ar']);

        $this->actingAs($arabic)
            ->get('/admin/bills')
            ->assertOk()
            ->assertSee('32.50')                 // 30 kWh × $0.25 + $25 maintenance
            ->assertDontSee('٢٥');                 // Arabic-Indic digits must never appear
    }
}
