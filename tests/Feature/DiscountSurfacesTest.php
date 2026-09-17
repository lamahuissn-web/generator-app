<?php

namespace Tests\Feature;

use App\Admin\Tables\BillsTable;
use App\Admin\Tables\SubscribersTable;
use App\Models\Bill;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Reports\OverdueReport;
use App\Reports\ProfitLossReport;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Surfaces built on the money layer: the exempt filter/indicator reads the new columns,
 * the discount action refuses a paid invoice through the table path, and the month's
 * disclosure figures (discounts / exempt_value) are right.
 */
class DiscountSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'a@a.com', 'password' => 'x',
            'role' => 'admin', 'locale' => 'ar',
        ]);
        $this->actingAs($this->admin);
    }

    private int $seq = 0;

    private function route(): Route
    {
        return Route::firstOrCreate(['name' => 'Test Area']);
    }

    private function flatSub(float $fee = 60.00): Subscriber
    {
        $this->seq++;
        return Subscriber::create([
            'route_id' => $this->route()->id, 'name' => 'بيت الفحص '.$this->seq,
            'type' => Subscriber::TYPE_FLAT, 'flat_fee' => $fee,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
    }

    // ---- the exempt filter / indicator reads the new columns ------------------

    public function test_the_exempt_filter_lists_only_exempt_houses(): void
    {
        $free = $this->flatSub(60);
        $paid = $this->flatSub(60);
        BillingService::exemptSubscriber($free, 'مسجد', 'مسجد', $this->admin->id);

        $table = app(SubscribersTable::class);
        $filter = collect($table->filters())->firstWhere('key', 'exempt');
        $this->assertNotNull($filter, 'the «المعفون فقط» filter exists');

        $onlyExempt = Subscriber::query();
        ($filter['apply'])($onlyExempt, true);
        $this->assertTrue($onlyExempt->get()->contains('id', $free->id));
        $this->assertFalse($onlyExempt->get()->contains('id', $paid->id));

        $onlyPaid = Subscriber::query();
        ($filter['apply'])($onlyPaid, false);
        $this->assertFalse($onlyPaid->get()->contains('id', $free->id));
        $this->assertTrue($onlyPaid->get()->contains('id', $paid->id));
    }

    public function test_the_exempt_indicator_reads_the_new_columns(): void
    {
        $sub = $this->flatSub();
        BillingService::exemptSubscriber($sub, 'مسجد الحي', 'مسجد', $this->admin->id);
        $sub->refresh();

        $table = app(SubscribersTable::class);
        $col = collect($table->columns())->firstWhere('key', 'is_exempt');
        $this->assertNotNull($col, 'the «معفى» indicator column exists');

        $render = ($col['render'])($sub);
        $this->assertStringContainsString('مسجد', $render, 'category shown in the row');

        $plain = $this->flatSub();
        $this->assertSame('', ($col['render'])($plain), 'non-exempt renders empty');
    }

    // ---- the discount action refuses a paid invoice through the table path ----

    public function test_the_discount_action_is_hidden_on_a_paid_invoice(): void
    {
        $sub = $this->flatSub(60);
        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');
        BillingService::collect($sub->fresh(), 60, $this->admin->id);
        $bill->refresh();

        $table = app(BillsTable::class);
        $action = collect($table->rowActions())->firstWhere('key', 'discount');
        $this->assertNotNull($action, 'the «خصم» action exists');

        $this->assertTrue(($action['hidden'])($bill), 'paid invoice hides the discount');

        $unpaid = $this->flatSub(60);
        $bill2 = BillingService::ensureFlatBill($unpaid->fresh(), '2026-09');
        $this->assertFalse(($action['hidden'])($bill2), 'unpaid invoice shows the discount');
    }

    public function test_the_discount_action_is_hidden_on_a_special_invoice(): void
    {
        $sub = $this->flatSub();
        $special = Bill::create([
            'number' => 'INV-2026-09-9999', 'subscriber_id' => $sub->id, 'period' => '2026-09',
            'type' => 'special', 'title' => 'قطعة',
            'usage_amount' => 0, 'maintenance_fee' => 0, 'flat_amount' => 0,
            'items_amount' => 20, 'prev_debt' => 0, 'total' => 20, 'paid_amount' => 0,
        ]);

        $table = app(BillsTable::class);
        $action = collect($table->rowActions())->firstWhere('key', 'discount');
        $this->assertTrue(($action['hidden'])($special), 'special invoice hides the discount');
    }

    // ---- report's disclosure figures ------------------------------------------

    public function test_the_month_shows_what_was_given_away(): void
    {
        $month = '2026-09';

        // One flat subscriber of 60 — discounted 20 on its single invoice.
        $a = $this->flatSub(60);
        $billA = BillingService::ensureFlatBill($a->fresh(), $month);
        BillingService::discountBill($billA, 20, 'حالة إنسانية', 'حالة إنسانية', $this->admin->id);

        // One exempt flat house of 60 — the month does not bill it; its 60 is waived value.
        $b = $this->flatSub(60);
        BillingService::exemptSubscriber($b, 'مسجد', 'مسجد', $this->admin->id);

        // A third house, exempt AND invoiced: its waiver is written into an invoice as a subsidy, so it
        // must be counted there and NOT again as waived value (or the month would report it twice).
        $c = $this->flatSub(60);
        BillingService::exemptSubscriber($c, 'مسجد', 'مسجد', $this->admin->id);
        BillingService::ensureFlatBill($c->fresh(), $month);

        $data = ProfitLossReport::forMonth($month)->data();

        $this->assertSame(20.0, $data['discounts'], 'the waiver the office granted by hand, SUM(bills.discount_amount)');
        $this->assertSame(60.0, $data['subsidies'], 'the exempt house that WAS invoiced carries its waiver on the invoice');
        $this->assertSame(60.0, $data['exempt_value'], 'only the house the month did not bill is waived value');
        // Net stays earned − expenses, never reduced by the disclosure.
        $this->assertSame(0.0, $data['net'], 'no invoices settled yet, so net is 0');
        $this->assertSame(40.0, $data['invoiced'], 'only the discounted invoice is in SUM(total)');
    }

    public function test_exempt_houses_are_absent_from_the_overdue_chase(): void
    {
        $sub = $this->flatSub(60);
        BillingService::exemptSubscriber($sub, 'مسجد', 'مسجد', $this->admin->id);
        // Give him no bill at all — but ensure he would have been overdue if billed
        // (no bill to owe, so summary must be 0).
        $other = $this->flatSub(60);
        Bill::create([
            'number' => 'INV-2026-08-0001', 'subscriber_id' => $other->id, 'period' => '2026-08',
            'type' => Subscriber::TYPE_FLAT, 'total' => 60, 'paid_amount' => 0,
            'usage_amount' => 0, 'maintenance_fee' => 0, 'flat_amount' => 60,
        ]);

        $rows = OverdueReport::make()->rows();
        $this->assertFalse($rows->contains(fn (array $r) => $r['id'] === $sub->id), 'exempt house not chased');
        $this->assertTrue($rows->contains(fn (array $r) => $r['id'] === $other->id), 'other house still chased');

        $summary = OverdueReport::make()->summary();
        $this->assertSame(60.0, $summary['total'], 'only the non-exempt debt is in the summary');
    }
}
