<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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

        // Expense data so Finance pages render their populated (non-empty) state.
        \App\Models\Expense::create([
            'category' => \App\Models\Expense::CAT_FUEL,
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

    public function test_admin_resources_render(): void
    {
        $sub = Subscriber::first();
        $paths = [
            '/admin/subscribers',
            '/admin/subscribers/create',
            '/admin/subscribers/'.$sub->id,          // interactive profile (infolist + relation managers)
            '/admin/routes',
            '/admin/routes/create',
            '/admin/readings',
            '/admin/readings/create',
            '/admin/bills',
            '/admin/payments',
            '/admin/expenses',
            '/admin/expenses/create',
            '/admin/profit-loss',
            '/admin/overdue-report',
            '/admin/users',
            '/admin/users/create',
            '/admin/settings',
            '/admin/audit-logs',
        ];

        foreach ($paths as $path) {
            $this->actingAs($this->admin)->get($path)->assertOk($path.' failed to render');
        }
    }

    public function test_fresh_data_leaves_audit_consistency(): void
    {
        $sub = Subscriber::first();
        $sumBills = (float) $sub->bills()->sum('total');
        $sumPayments = (float) Payment::where('subscriber_id', $sub->id)->sum('amount');

        // outstanding() == billed - paid always
        $this->assertEqualsWithDelta($sumBills - $sumPayments, $sub->outstanding(), 0.01);
    }

    public function test_admin_panel_chrome_renders_in_arabic(): void
    {
        $arAdmin = User::create(['name' => 'مدير', 'email' => 'ar@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'ar']);

        $this->actingAs($arAdmin)
            ->get('/admin/subscribers')
            ->assertOk()
            ->assertSee('المشتركون');

        $this->actingAs($arAdmin)
            ->get('/admin/profit-loss')
            ->assertOk()
            ->assertSee('صافي الربح');

        // Table headers follow the locale too (not just chrome).
        $this->actingAs($arAdmin)
            ->get('/admin/bills')
            ->assertOk()
            ->assertSee('المشترك')
            ->assertSee('الفترة')
            ->assertSee('المدفوع')
            // Numbers stay Western (0-9) even when the UI is Arabic.
            ->assertSee('32.50')
            ->assertDontSee('٣٢'); // Arabic-Indic digits must never appear
    }
}
