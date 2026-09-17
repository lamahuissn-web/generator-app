<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who is not charged, why, and for how much (Feature 004).
 *
 * Three mechanisms, one rule: a discount reduces what the office CHARGED — it is never money the office
 * holds, so it never touches subscribers.credit, and the invoice it lands on still adds up on its own
 * terms. An exemption is a standing decision about a house; a discount is a decision about one invoice.
 */
class ExemptionAndDiscountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'a@a.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'ar',
        ]);
    }

    private function flatSub(float $fee = 60.00): Subscriber
    {
        $route = Route::create(['name' => 'Test Area']);

        return Subscriber::create([
            'route_id' => $route->id, 'name' => 'بيت الفحص', 'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => $fee, 'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
    }

    private function meteredSub(float $maintenance = 15.00): Subscriber
    {
        $route = Route::create(['name' => 'Test Area']);

        return Subscriber::create([
            'route_id' => $route->id, 'name' => 'بيت العدّاد', 'meter_no' => 'M-1',
            'type' => Subscriber::TYPE_METERED, 'amperage' => 15, 'maintenance_fee' => $maintenance,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
    }

    private function creditOf(Subscriber $sub): float
    {
        return round((float) $sub->fresh()->credit, 2);
    }

    // ---- the exempt house -------------------------------------------------------------------

    public function test_an_exemption_needs_a_reason(): void
    {
        $sub = $this->flatSub();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('refuse.exempt_reason'));

        BillingService::exemptSubscriber($sub, '   ');
    }

    public function test_an_exempt_house_is_not_invoiced_by_the_run(): void
    {
        $sub = $this->flatSub();
        BillingService::exemptSubscriber($sub, 'مسجد الحي', 'مسجد', $this->admin->id);

        $preview = BillingService::periodRun('2026-09', null, false);
        $row = collect($preview['rows'])->firstWhere('subscriber_id', $sub->id);

        $this->assertSame(BillingService::RUN_EXEMPT, $row['state']);

        $committed = BillingService::periodRun('2026-09', null, true, $this->admin->id);

        $this->assertSame(0, $committed['created']);
        $this->assertSame(1, $committed['summary'][BillingService::RUN_EXEMPT]);
        $this->assertDatabaseCount('bills', 0);
        $this->assertSame(0.0, $sub->fresh()->outstanding(), 'and he is not chased for anything');
    }

    public function test_an_exempt_metered_house_is_still_read_and_charged_nothing(): void
    {
        $sub = $this->meteredSub(15.00);
        Reading::create([
            'subscriber_id' => $sub->id, 'period' => '2026-06',
            'current_reading' => 1000, 'previous_reading' => 0, 'consumption' => 0,
        ]);
        BillingService::exemptSubscriber($sub, 'قريب صاحب المكتب', 'قريب', $this->admin->id);

        $result = BillingService::enterMeteredReading($sub->fresh(), '2026-07', 1040, $this->admin->id);
        $bill = $result['bill'];

        $this->assertSame(40.0, (float) $result['reading']->consumption, 'the meter is on record as always');
        $this->assertSame(0.0, (float) $bill->total, 'and nothing is claimed from the house');
        $this->assertSame((float) $bill->usage_amount + (float) $bill->maintenance_fee, (float) $bill->subsidy_amount);
        $this->assertSame(0.0, (float) $bill->discount_amount, 'nobody waived anything by hand: the exemption is arithmetic');
        $this->assertSame(0.0, round($bill->charges(), 2), 'the invoice adds up to nothing');
        $this->assertSame(0.0, $sub->fresh()->outstanding());
        $this->assertSame(__('f.discount_exempt', ['reason' => 'قريب صاحب المكتب']), $bill->discount_note);
    }

    public function test_removing_an_exemption_bills_the_house_again(): void
    {
        $sub = $this->flatSub();
        BillingService::exemptSubscriber($sub, 'حالة مؤقتة', null, $this->admin->id);
        BillingService::unexemptSubscriber($sub, $this->admin->id);

        $result = BillingService::periodRun('2026-09', null, true, $this->admin->id);

        $this->assertSame(1, $result['created']);
        $this->assertSame(60.0, (float) Bill::first()->total);
    }

    // ---- the permanent subsidy --------------------------------------------------------------

    public function test_a_permanent_percent_comes_off_the_charge_and_the_paper_still_adds_up(): void
    {
        $sub = $this->flatSub(60.00);
        $sub->forceFill(['discount_percent' => 25])->save();

        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');

        $this->assertSame(15.0, (float) $bill->subsidy_amount);
        $this->assertSame(45.0, (float) $bill->total);
        $this->assertSame((float) $bill->total, round($bill->charges(), 2));
        $this->assertSame(__('f.discount_permanent', ['percent' => '25.00']), $bill->discount_note);
        $this->assertSame(0.0, $this->creditOf($sub), 'a subsidy is not the subscriber\'s money');
    }

    public function test_a_subsidy_is_applied_before_the_balance_he_carries(): void
    {
        $sub = $this->flatSub(60.00);
        $sub->forceFill(['discount_percent' => 50])->save();
        BillingService::saveCredit($sub, 10.00);

        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');

        // 60 − 30 (subsidy) = 30 charged, less the 10 he carries = 20.
        $this->assertSame(30.0, (float) $bill->subsidy_amount);
        $this->assertSame(10.0, (float) $bill->credit_applied);
        $this->assertSame(20.0, (float) $bill->total);
        $this->assertSame(0.0, $this->creditOf($sub), 'and his balance was spent exactly as usual');
    }

    // ---- the one-cycle discount --------------------------------------------------------------

    public function test_a_discount_reduces_the_invoice_and_can_be_taken_back(): void
    {
        $sub = $this->flatSub(60.00);
        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');

        BillingService::discountBill($bill, 20.00, 'حالة إنسانية', 'حالة إنسانية', $this->admin->id);

        $bill->refresh();
        $this->assertSame(20.0, (float) $bill->discount_amount);
        $this->assertSame(40.0, (float) $bill->total);
        $this->assertSame(40.0, round($bill->charges(), 2));
        $this->assertSame('حالة إنسانية', $bill->discount_note);
        $this->assertNotNull($bill->discount_at);
        $this->assertSame($this->admin->id, (int) $bill->discount_by);
        $this->assertSame(40.0, $sub->fresh()->outstanding());

        // Taking it back restores the invoice exactly while it is unpaid.
        BillingService::discountBill($bill->fresh(), 0.0, '');

        $bill->refresh();
        $this->assertSame(0.0, (float) $bill->discount_amount);
        $this->assertSame(60.0, (float) $bill->total);
        $this->assertNull($bill->discount_note);
        $this->assertSame(60.0, round($bill->charges(), 2));
    }

    public function test_a_free_month_is_the_whole_charge_and_the_invoice_still_exists(): void
    {
        $sub = $this->flatSub(60.00);
        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');

        BillingService::discountBill($bill, 60.00, 'شهر مجاني', null, $this->admin->id);

        $bill->refresh();
        $this->assertSame(0.0, (float) $bill->total);
        $this->assertSame(60.0, (float) $bill->discount_amount);
        $this->assertSame(0.0, round($bill->charges(), 2), 'the paper shows the month and its waiver');
        $this->assertSame(0.0, $sub->fresh()->outstanding());
        $this->assertDatabaseCount('bills', 1);
    }

    public function test_a_discount_needs_a_reason(): void
    {
        $sub = $this->flatSub(60.00);
        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('refuse.discount_reason'));

        BillingService::discountBill($bill, 10.00, '  ');
    }

    // ---- two kinds of waived money, two columns (see the split migration) --------------------

    public function test_a_discount_sits_on_top_of_the_subsidy_and_never_raises_the_total(): void
    {
        $sub = $this->flatSub(60.00);
        $sub->forceFill(['discount_percent' => 25])->save();

        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');
        $this->assertSame(15.0, (float) $bill->subsidy_amount, 'the standing subsidy has a column of its own');
        $this->assertSame(0.0, (float) $bill->discount_amount, 'and nobody waived anything by hand yet');
        $this->assertSame(45.0, (float) $bill->total);

        $discounted = BillingService::discountBill($bill, 5.00, 'خصم صغير', 'أخرى', $this->admin->id);

        // The office's own waiver grows ON TOP of the subsidy: granting a discount must never charge more.
        $this->assertSame(5.0, (float) $discounted->discount_amount);
        $this->assertSame(15.0, (float) $discounted->subsidy_amount, 'the subsidy is untouched');
        $this->assertSame(40.0, (float) $discounted->total, '60 − 15 (subsidy) − 5 (waiver)');
        $this->assertSame((float) $discounted->total, round($discounted->charges(), 2), 'charges() still equals total');
        $this->assertLessThanOrEqual(45.0, (float) $discounted->total, 'a discount never raises a total');

        // Taking it back leaves the subsidy exactly where it was.
        $back = BillingService::discountBill($discounted, 0, '', null, $this->admin->id);
        $this->assertSame(0.0, (float) $back->discount_amount);
        $this->assertSame(15.0, (float) $back->subsidy_amount);
        $this->assertSame(45.0, (float) $back->total);
    }

    public function test_an_exempt_houses_waiver_is_arithmetic_not_a_decision(): void
    {
        $sub = $this->flatSub(60.00);
        BillingService::exemptSubscriber($sub, 'مسجد', 'مسجد', $this->admin->id);

        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');

        $this->assertSame(60.0, (float) $bill->subsidy_amount, 'the waiver is arithmetic written into the invoice');
        $this->assertSame(0.0, (float) $bill->discount_amount, 'nobody decided anything, so nobody is named');
        $this->assertNull($bill->discount_by);
        $this->assertSame(0.0, (float) $bill->total);
        $this->assertSame((float) $bill->total, round($bill->charges(), 2));
    }

    public function test_a_bill_cannot_be_written_out_of_balance(): void
    {
        $sub = $this->flatSub(60.00);

        // An invoice that states its own breakdown cannot contradict it. This is the door the audit found
        // open: Bill::create with a real charge of 40 and a total of 999 (money never charged).
        $this->expectException(\RuntimeException::class);

        Bill::create([
            'number' => 'INV-2026-09-7777', 'subscriber_id' => $sub->id, 'period' => '2026-09',
            'type' => Subscriber::TYPE_FLAT, 'flat_amount' => 60, 'subsidy_amount' => 15,
            'discount_amount' => 5, 'total' => 999, 'paid_amount' => 0,
        ]);
    }

    public function test_an_invoice_whose_own_lines_add_up_is_written_normally(): void
    {
        $sub = $this->flatSub(60.00);

        $bill = Bill::create([
            'number' => 'INV-2026-09-7778', 'subscriber_id' => $sub->id, 'period' => '2026-09',
            'type' => Subscriber::TYPE_FLAT, 'flat_amount' => 60, 'subsidy_amount' => 15,
            'discount_amount' => 5, 'total' => 40, 'paid_amount' => 0,
        ]);

        $this->assertSame(40.0, (float) $bill->total);
        $this->assertSame((float) $bill->total, round($bill->charges(), 2));

        // An invoice written as a total alone states no breakdown, so there is nothing to contradict —
        // legacy rows and the office's own paper fixtures keep working.
        $bare = Bill::create([
            'number' => 'INV-2026-09-7779', 'subscriber_id' => $sub->id, 'period' => '2026-09',
            'type' => Subscriber::TYPE_FLAT, 'total' => 60, 'paid_amount' => 0,
        ]);

        $this->assertSame(60.0, (float) $bare->total);
    }

    public function test_a_discount_cannot_exceed_the_invoice_charge(): void
    {
        $sub = $this->flatSub(60.00);
        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('refuse.discount_over', ['charge' => money_ltr(60.0)]));

        BillingService::discountBill($bill, 60.01, 'أكثر من الفاتورة');
    }

    public function test_a_paid_invoice_cannot_be_discounted(): void
    {
        $sub = $this->flatSub(60.00);
        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');
        BillingService::collect($sub->fresh(), 60.00, $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('refuse.discount_paid', ['amount' => money_ltr(60.0)]));

        BillingService::discountBill($bill->fresh(), 10.00, 'متأخر');
    }

    public function test_a_special_invoice_cannot_be_discounted(): void
    {
        $sub = $this->flatSub(60.00);
        $special = Bill::create([
            'number' => 'INV-2026-09-9999', 'subscriber_id' => $sub->id, 'period' => '2026-09',
            'type' => 'special',
            'title' => 'قطعة', 'usage_amount' => 0, 'maintenance_fee' => 0, 'flat_amount' => 0,
            'items_amount' => 20, 'prev_debt' => 0, 'total' => 20, 'paid_amount' => 0,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('refuse.discount_special'));

        BillingService::discountBill($special, 5.00, 'خصم');
    }

    public function test_a_discount_is_never_written_to_the_subscribers_balance(): void
    {
        $sub = $this->flatSub(60.00);
        $bill = BillingService::ensureFlatBill($sub->fresh(), '2026-09');

        BillingService::discountBill($bill, 20.00, 'خصم', null, $this->admin->id);

        $this->assertSame(0.0, $this->creditOf($sub), 'a waiver is a reduction of the charge, not his money');
        $this->assertDatabaseCount('payments', 0);
    }
}
