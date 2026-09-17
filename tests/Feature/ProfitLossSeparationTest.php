<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Reports\ProfitLossReport;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The drawer and the books, read apart.
 *
 * A receipt carries two kinds of money: what settled invoices — the office's — and what is simply
 * the subscriber's, waiting on his account because the collector takes a whole number and carries
 * no change. Profit has to answer on the first, or the office counts a liability as income and
 * prints a figure it cannot touch. These tests pin the three figures apart, and the fallback for
 * receipts written before the column existed.
 */
class ProfitLossSeparationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'a@a.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en',
        ]);
    }

    private function flatSub(float $fee): Subscriber
    {
        $route = Route::create(['name' => 'Test Area']);

        return Subscriber::create([
            'route_id' => $route->id,
            'name' => 'بيت الأرباح',
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => $fee,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
    }

    private function expense(float $amount): void
    {
        Expense::create([
            'category' => 'fuel',
            'description' => 'ديزل',
            'amount' => $amount,
            'expense_date' => now()->toDateString(),
            'entered_by' => $this->admin->id,
        ]);
    }

    public function test_cash_earned_and_what_is_held_are_three_different_figures(): void
    {
        $month = now()->format('Y-m');
        $sub = $this->flatSub(51.60);
        BillingService::ensureFlatBill($sub, $month);
        BillingService::collect($sub->fresh(), 52.00, $this->admin->id); // no change at the door
        $this->expense(10.00);

        $report = ProfitLossReport::forMonth($month)->data();

        $this->assertSame(51.60, $report['invoiced']);
        $this->assertSame(52.00, $report['collected'], 'the drawer holds the cash that changed hands');
        $this->assertSame(51.60, $report['earned'], 'the invoice it settled is what the office made');
        $this->assertSame(0.40, $report['credit_added'], 'the rest is the subscriber\'s money');
        $this->assertSame(0.40, $report['held_for_subscribers'], 'and the office is holding it for him');
        $this->assertSame(41.60, $report['net'], 'profit is EARNED minus expenses, not the drawer');
    }

    public function test_a_receipt_written_before_the_column_counts_its_whole_amount(): void
    {
        $month = now()->format('Y-m');
        $sub = $this->flatSub(20.00);
        Payment::create([
            'number' => 'REC-OLD-0001',
            'subscriber_id' => $sub->id,
            'collector_id' => $this->admin->id,
            'amount' => 20.00,
            'applied' => null, // a row from before the split: the whole receipt settled bills
            'method' => Payment::METHOD_CASH_USD,
        ]);

        $report = ProfitLossReport::forMonth($month)->data();

        $this->assertSame(20.00, $report['earned']);
        $this->assertSame(0.00, $report['credit_added'], 'nothing of an old receipt was held back');
    }

    public function test_what_an_invoice_spends_of_his_balance_is_named(): void
    {
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);
        $month = now()->format('Y-m');
        $sub = $this->flatSub(51.60);
        BillingService::saveCredit($sub, 0.80);

        $bill = BillingService::ensureFlatBill($sub->fresh(), $month); // 51.60 − 0.80 -> 51.00

        $report = ProfitLossReport::forMonth($month)->data();

        $this->assertSame(51.00, (float) $bill->total);
        $this->assertSame(0.60, $report['credit_spent'], 'the invoice names the balance it used');
        $this->assertSame(0.20, $report['held_for_subscribers'], 'and the rest is still his');
    }

    public function test_the_screen_shows_the_two_sides_apart(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.profit-loss'))
            ->assertOk()
            ->assertSee(__('pl.earned'), false)
            ->assertSee(__('pl.held'), false);
    }

    public function test_a_back_dated_receipt_lands_in_the_month_it_was_collected(): void
    {
        $sub = $this->flatSub(20.00);
        $lastMonth = now()->subMonthNoOverflow();

        Payment::create([
            'number' => 'REC-LAST-0001',
            'subscriber_id' => $sub->id,
            'collector_id' => $this->admin->id,
            'amount' => 20.00,
            'applied' => 20.00,
            'method' => Payment::METHOD_CASH_USD,
            'collected_on' => $lastMonth->copy()->endOfMonth()->toDateString(),
        ]);

        $this->assertSame(20.00, ProfitLossReport::forMonth($lastMonth->format('Y-m'))->data()['earned']);
        $this->assertSame(
            0.00,
            ProfitLossReport::forMonth(now()->format('Y-m'))->data()['earned'],
            'money collected for last month is not this month\'s earning'
        );
    }
}
