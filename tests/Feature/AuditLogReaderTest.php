<?php

namespace Tests\Feature;

use App\Admin\Actions\ViewAuditEntry;
use App\Admin\AuditNarrative;
use App\Admin\Tables\AuditLogsTable;
use App\Livewire\Admin\ActionForm;
use App\Livewire\Admin\DataTable;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Equipment;
use App\Models\Refuelling;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * سجل التدقيق, read the way the office reads (Feature 007).
 *
 * The trail is written by every other feature; what this file pins is the READING of it, because that is what
 * was wrong: the payload's first four keys and then `+8` (a reading row hiding the household's name, the
 * previous reading and the invoice amount), a nested block dumped as JSON with the figure buried inside it, a
 * raw English slug as the badge, no way to open a row, and no filter an operator would think of.
 *
 * Every assertion here reads RENDERED html, because that is the only place these defects ever appeared — the
 * engine was always correct and the payload always complete.
 */
class AuditLogReaderTest extends TestCase
{
    use RefreshDatabase;

    private const MONTH = '2026-09';

    private User $admin;

    private User $collector;

    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'مدير', 'email' => 'admin@aud.com', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);

        $this->collector = User::create([
            'name' => 'جابي', 'email' => 'col@aud.com', 'password' => bcrypt('secret'),
            'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'منطقة الفحص']);
    }

    private function metered(string $name = 'الحاج'): Subscriber
    {
        return Subscriber::create([
            'route_id' => $this->route->id, 'name' => $name, 'type' => Subscriber::TYPE_METERED,
            'meter_no' => 'M-'.uniqid(), 'amperage' => 10, 'maintenance_fee' => 10,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
    }

    /** A row exactly as some other feature writes it. */
    private function entry(string $action, array $payload, ?string $type = null, ?int $id = null, ?User $actor = null, ?string $at = null): AuditLog
    {
        $log = AuditLog::create([
            'actor_id' => ($actor ?? $this->admin)->id,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'payload' => $payload,
        ]);

        if ($at) {
            $log->forceFill(['created_at' => $at])->save();
        }

        return $log->refresh();
    }

    private function table(?User $as = null): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($as ?? $this->admin)->test(DataTable::class, ['table' => AuditLogsTable::class]);
    }

    // ---- the sentence, instead of four keys and a +N --------------------------------------------

    public function test_a_reading_row_names_the_house_and_the_invoice_amount(): void
    {
        $subscriber = $this->metered('الحاج');
        BillingService::enterMeteredReading($subscriber, self::MONTH, 210, $this->admin->id);
        $bill = Bill::where('subscriber_id', $subscriber->id)->firstOrFail();

        $html = $this->table()->html();

        $this->assertStringContainsString('قراءة جديدة', $html, 'the act is named in Arabic');
        $this->assertStringContainsString('الحاج', $html, 'the household is named');
        $this->assertStringContainsString(number_format((float) $bill->total, 2), $html, 'the invoice amount is on the line');
        $this->assertStringNotContainsString('+8', $html, 'nothing is cut off with a +N marker');
        $this->assertStringNotContainsString('bill_id:', $html, 'and no raw payload key is dumped');
    }

    public function test_a_removed_refuelling_shows_its_litres_instead_of_a_json_block(): void
    {
        $device = Equipment::create([
            'name' => 'المولّد الرئيسي', 'kind' => Equipment::KIND_GENERATOR, 'status' => Equipment::STATUS_WORKING,
            'engine_hours_opening' => 0, 'created_by' => $this->admin->id,
        ]);
        $row = Refuelling::create([
            'equipment_id' => $device->id, 'date' => self::MONTH.'-08', 'litres' => 200, 'price_per_litre' => 1.3,
            'kwh_source' => Refuelling::SOURCE_ESTIMATED, 'created_by' => $this->admin->id,
        ]);

        $this->entry('refuelling.removed', [
            'removed' => ['date' => '2026-09-08', 'litres' => 200, 'price_per_litre' => 1.3, 'engine_hours' => 4300],
            'cycle_closed' => false,
            'reason' => null,
        ], Refuelling::class, $row->id);

        $html = $this->table()->html();

        $this->assertStringContainsString('حذف تعبئة', $html);
        $this->assertStringContainsString('200', $html, 'the litres are read, not buried');
        $this->assertStringContainsString('$1.300', $html);
        $this->assertStringNotContainsString('{"date"', $html, 'no JSON blob reaches the screen');
        $this->assertStringNotContainsString('&quot;date&quot;', $html);
    }

    public function test_a_closed_month_correction_says_why_and_which_month_it_was(): void
    {
        $this->entry('refuelling.corrected', [
            'before' => ['litres' => 100, 'price_per_litre' => 1.25, 'engine_hours' => 4300],
            'after' => ['litres' => 140, 'price_per_litre' => 1.25, 'engine_hours' => 4300],
            'replaced_row_id' => 6,
            'cycle_closed' => true,
            'reason' => 'رقم مقلوب',
        ], Refuelling::class, 7);

        $html = $this->table()->html();

        $this->assertStringContainsString('تصحيح تعبئة', $html);
        $this->assertStringContainsString('رقم مقلوب', $html, 'the reason is on the line, not behind a click');
        $this->assertStringContainsString('شهر مقفّل', $html);
    }

    // ---- the detail: everything, read-only -------------------------------------------------------

    public function test_the_detail_shows_what_moved_and_hides_what_did_not(): void
    {
        $entry = $this->entry('refuelling.corrected', [
            'before' => ['litres' => 100, 'price_per_litre' => 1.25, 'engine_hours' => 4300, 'notes' => null],
            'after' => ['litres' => 140, 'price_per_litre' => 1.25, 'engine_hours' => 4300, 'notes' => 'بعد التصحيح'],
            'replaced_row_id' => 6,
            'cycle_closed' => false,
            'reason' => null,
        ], Refuelling::class, 7);

        $html = $this->modal($entry);

        $this->assertStringContainsString('100 ← 140', preg_replace('/\s*←\s*/u', ' ← ', $html), 'the change reads as before → after');
        $this->assertStringNotContainsString('ساعات المحرك', $html, 'a field that did not move is not printed as a change');
        $this->assertStringContainsString('ملاحظة', $html, 'a field the correction ADDED is shown');
    }

    public function test_the_detail_carries_every_key_and_writes_nothing(): void
    {
        // The shape of a real reading row: twelve keys, four of which the old column showed.
        $subscriber = $this->metered();
        $reading = \App\Models\Reading::create([
            'subscriber_id' => $subscriber->id, 'period' => self::MONTH, 'current_reading' => 210,
            'previous_reading' => 175, 'consumption' => 35,
        ]);
        $entry = $this->entry('reading.created', [
            'period' => self::MONTH, 'current_reading' => 210, 'consumption' => 35, 'bill_id' => 21,
            'total' => 22.5, 'prev_debt' => 0, 'estimated' => false, 'estimate_reason' => null,
            'meter_case_open' => false, 'estimate_extension' => false, 'estimates_used' => null, 'meter_swap' => null,
        ], \App\Models\Reading::class, $reading->id);

        $before = AuditLog::count();
        $html = $this->modal($entry);

        foreach (['سبب التقدير', 'حالة عدّاد مفتوحة', 'تمديد تقديري', 'تبديل عدّاد', 'الإجراء (تقني)'] as $label) {
            $this->assertStringContainsString($label, $html, $label.' is reachable');
        }

        $this->assertStringContainsString($subscriber->name, $html, 'the household is named');
        $this->assertStringContainsString('reading.created', $html, 'the raw action stays for a support call');
        $this->assertStringNotContainsString('{"period"', $html, 'no JSON');

        // Submitting the read-only modal changes nothing at all — starting with the trail itself.
        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => ViewAuditEntry::class, 'subjectId' => $entry->id])
            ->call('openModal')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame($before, AuditLog::count(), 'reading the trail does not write to it');
    }

    public function test_an_action_nobody_templated_still_reads(): void
    {
        $entry = $this->entry('something.odd', ['alpha' => 1, 'beta' => 'نص']);

        $html = $this->table()->assertOk()->html();

        $this->assertStringContainsString('something.odd', $html, 'its own key is the title');

        // And its payload is still reachable, as pairs, in the detail — no exception, no JSON.
        $detail = $this->modal($entry);
        $this->assertStringContainsString('alpha', $detail);
        $this->assertStringContainsString('beta', $detail);
        $this->assertStringContainsString('نص', $detail);
    }

    // ---- filters the office would use ------------------------------------------------------------

    public function test_the_filters_narrow_by_kind_who_and_month(): void
    {
        $device = Equipment::create([
            'name' => 'المولّد', 'kind' => Equipment::KIND_GENERATOR, 'status' => Equipment::STATUS_WORKING,
            'engine_hours_opening' => 0, 'created_by' => $this->admin->id,
        ]);
        $this->assertNotNull($device->id);

        $collectorRow = $this->metered('سامي');
        $readingHouse = $this->metered('ناديا');
        $reading = \App\Models\Reading::create([
            'subscriber_id' => $readingHouse->id, 'period' => self::MONTH,
            'current_reading' => 100, 'previous_reading' => 0, 'consumption' => 100,
        ]);

        // Both fixtures name their subject the way the app's own rows do: a payment carries subscriber_id, a
        // reading points at the house through the Reading it recorded.
        $this->entry('reading.created', ['period' => self::MONTH, 'current_reading' => 100], \App\Models\Reading::class, $reading->id, null, '2026-09-15 10:00:00');
        $this->entry('payment.created', ['subscriber_id' => $collectorRow->id, 'cash' => 20, 'applied' => 20], null, null, $this->collector, '2026-10-02 09:00:00');

        // By kind: money only. The assertions name a ROW by the act's colon form («قراءة جديدة: » — the
        // sentence's opening), never the bare title: the action dropdown lists every title as an option, so
        // the bare string is on the page in every filter state and would make these pass for the wrong reason.
        $this->table()->set('filters.family', AuditNarrative::FAMILY_MONEY)
            ->assertDontSee('قراءة جديدة: ')
            ->assertSee('قبض: ');

        // By person: the collector's row only.
        $this->table()->set('filters.actor', $this->collector->id)
            ->assertSee('قبض: ')
            ->assertDontSee('قراءة جديدة: ');

        // By month: September only.
        $this->table()->set('filters.month', '2026-09')
            ->assertSee('قراءة جديدة: ')
            ->assertDontSee('قبض: ');

        // And the action dropdown offers Arabic, not the raw slug. (It lists the actions that actually
        // OCCUR in the trail, which is the point — a filter longer than the list it filters is noise.)
        $filters = (new AuditLogsTable)->filters();
        $options = ($filters[3]['options_from'])();

        $this->assertSame('قراءة جديدة', $options['reading.created']);
        $this->assertSame('قبض', $options['payment.created']);
    }

    public function test_every_action_the_app_writes_has_a_title_and_a_label_for_its_keys(): void
    {
        // A missing translation renders as a raw slug to an Arabic-only operator, and nothing else in the
        // suite would notice: the reader's keys are built dynamically, so the language sweep skips them.
        foreach (['reading.created', 'reading.deleted', 'reading.baseline', 'reading.imported',
            'bill.generated', 'bill.deleted', 'bill.special_created', 'bill.special_deleted', 'bill.discounted',
            'payment.created', 'payment.reversed', 'subscriber.exempted', 'subscriber.exemption_removed',
            'subscriber.converted_to_flat', 'subscriber.converted_to_metered', 'subscriber.meter_reported_broken',
            'subscriber.deleted', 'subscriber.imported', 'refuelling.corrected', 'refuelling.removed',
            'service.corrected', 'service.removed', 'expense.created', 'expense.updated', 'expense.deleted',
            'handover.created', 'handover.updated', 'handover.deleted', 'user.deleted'] as $action) {
            $narrative = AuditNarrative::of(new AuditLog(['action' => $action]));

            $this->assertTrue($narrative->isKnown(), $action.' has a title');
            $this->assertNotSame($narrative->title(), $action, $action.' is not left as a raw slug');
        }

        foreach (AuditNarrative::FAMILIES as $family) {
            $this->assertNotSame(__('aud.fam_'.$family), 'aud.fam_'.$family, $family.' has an Arabic name');
        }
    }

    // ---- admin only, and no money moved ----------------------------------------------------------

    public function test_the_trail_and_its_detail_are_admin_only(): void
    {
        $entry = $this->entry('payment.created', ['cash' => 20, 'applied' => 20]);

        $this->assertFalse((new ViewAuditEntry)->visible($entry), 'a collector may not open the detail');

        $this->actingAs($this->collector);
        $response = $this->get(route('admin.audit-logs.index'));
        $this->assertContains($response->getStatusCode(), [403, 302, 401], 'the screen refuses a collector');
    }

    public function test_reading_the_screen_moves_no_money(): void
    {
        $subscriber = $this->metered();
        BillingService::enterMeteredReading($subscriber, self::MONTH, 210, $this->admin->id);
        BillingService::collect($subscriber->fresh(), 20.0, null, 'دفعة');

        $before = [
            'bills' => Bill::count(),
            'bills_total' => (float) Bill::sum('total'),
            'payments' => \App\Models\Payment::count(),
            'credit' => (float) Subscriber::sum('credit'),
            'entries' => AuditLog::count(),
        ];

        $this->table()->html();
        $this->table()->set('filters.family', AuditNarrative::FAMILY_READINGS)->html();

        foreach (AuditLog::all() as $row) {
            $row->action === 'something.odd' || $this->modal($row);
        }

        $this->assertSame($before, [
            'bills' => Bill::count(),
            'bills_total' => (float) Bill::sum('total'),
            'payments' => \App\Models\Payment::count(),
            'credit' => (float) Subscriber::sum('credit'),
            'entries' => AuditLog::count(),
        ], 'reading the trail changes no document and writes no entry');
    }

    private function modal(AuditLog $entry): string
    {
        return Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => ViewAuditEntry::class, 'subjectId' => $entry->id])
            ->call('openModal')
            ->html();
    }
}
