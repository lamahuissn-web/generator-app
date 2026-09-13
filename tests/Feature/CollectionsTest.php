<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\Collections;
use App\Models\AuditLog;
use App\Models\CashHandover;
use App\Models\Payment;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CashHandoverService;
use App\Services\CollectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * التحصيل والتسليم — the one screen the three cash screens became.
 *
 * The arithmetic itself (totals, the ledger's opening balance, the handover guard) is proven where
 * it lives, in CollectionReportTest; what is proven HERE is the MERGE and the auditing it was asked
 * to add: that the period figures and the all-time cash position never get confused, that a receipt
 * is legible down to the minute and the invoice it settled, that a settlement can be written,
 * edited and deleted from this one screen without its history disappearing, and that the three old
 * routes still land somewhere useful.
 *
 * The fixture is two collectors, one of them with an August collection and an August settlement
 * that fall OUTSIDE the September range on screen — without them the scope labels would prove
 * nothing.
 */
class CollectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $jad;

    private User $rami;

    private Subscriber $jadSub;

    private Subscriber $ramiSub;

    private Payment $jadAugust;

    private Payment $jadSeptember;

    private Payment $ramiSeptember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('Admin', 'admin@x.com', User::ROLE_ADMIN);
        $this->jad = $this->user('Jad Haddad', 'jad@x.com', User::ROLE_COLLECTOR);
        $this->rami = $this->user('Rami Nasser', 'rami@x.com', User::ROLE_COLLECTOR);

        $areaA = Route::create(['name' => 'Area A']);
        $areaB = Route::create(['name' => 'Area B']);

        $this->jadSub = $this->flatSubscriber('محمد عيتاني', $areaA, 60, ['2026-08', '2026-09']);
        $this->ramiSub = $this->flatSubscriber('سامي دندشي', $areaB, 40, ['2026-09']);

        // The field: Jad takes his subscriber's money on the spot, so the trail names HIM.
        $this->jadAugust = $this->pay($this->jadSub, 60, $this->jad, '2026-08-20', '2026-08');
        $this->jadSeptember = $this->pay($this->jadSub, 60, $this->jad, '2026-09-02', '2026-09');

        // The office: an admin records Rami's collection at the counter.
        $this->ramiSeptember = $this->pay($this->ramiSub, 40, $this->rami, '2026-09-03', '2026-09', $this->admin);

        // One settlement inside the period and one outside it: «سلّم» is ranged, «بيده» is not.
        CashHandoverService::create([
            'collector_id' => $this->jad->id, 'amount' => 10, 'handover_date' => '2026-08-31', 'note' => null,
        ], $this->admin->id);

        CashHandoverService::create([
            'collector_id' => $this->jad->id, 'amount' => 20, 'handover_date' => '2026-09-02', 'note' => null,
        ], $this->admin->id);
    }

    private function user(string $name, string $email, string $role): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => 'x', 'role' => $role, 'locale' => 'en',
        ]);
    }

    /** @param array<int, string> $periods */
    private function flatSubscriber(string $name, Route $route, float $fee, array $periods): Subscriber
    {
        $subscriber = Subscriber::create([
            'route_id' => $route->id,
            'name' => $name,
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => $fee,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        foreach ($periods as $period) {
            $bill = BillingService::ensureFlatBill($subscriber, $period);
            $bill->forceFill(['created_at' => Carbon::parse($period.'-01 09:00')])->save();
        }

        return $subscriber;
    }

    private function pay(Subscriber $subscriber, float $amount, User $collector, string $date, string $period, ?User $enteredBy = null): Payment
    {
        // The collector standing at the door, or the operator at the counter: whoever is signed in
        // when the money is entered is what the audit trail will name later.
        $this->actingAs($enteredBy ?? $collector);

        $payment = BillingService::collect($subscriber, $amount, $collector->id, null, $period)['payment'];
        $payment->forceFill(['created_at' => Carbon::parse($date.' 11:00')])->save();

        $this->actingAs($this->admin);

        return $payment;
    }

    /** The screen, with the September range the office would be looking at. */
    private function screen(?int $collectorId = null)
    {
        $component = Livewire::actingAs($this->admin)
            ->test(Collections::class)
            ->set('filters.from', '2026-09-01')
            ->set('filters.to', '2026-09-30');

        return $collectorId ? $component->call('selectCollector', $collectorId) : $component;
    }

    // ---- the scope rule ----------------------------------------------------

    public function test_the_period_figures_and_the_all_time_cash_position_are_told_apart(): void
    {
        $component = $this->screen();

        // September: two collections ($60 + $40) and one settlement ($20).
        $component->assertViewHas('totals', fn (array $totals): bool => $totals['collected'] === 100.0
            && $totals['handed'] === 20.0
            && $totals['payments_count'] === 2
            // …while the cash position knows about August as well: (120 + 40) − (10 + 20).
            && $totals['in_hand'] === 130.0);

        // Both scopes are ON the page, next to the figures they qualify.
        $component->assertSee(__('col.scope_period'))
            ->assertSee(__('col.scope_all_time'))
            ->assertSee(__('col.hdr_in_hand_all'));
    }

    public function test_a_row_carries_the_period_activity_beside_what_he_still_holds(): void
    {
        $rows = $this->screen()->instance()->collectors();

        $jad = $rows->firstWhere('collector_id', $this->jad->id);
        $this->assertSame(60.0, $jad['collected'], 'the period figure');
        $this->assertSame(20.0, $jad['handed'], 'the period figure');
        $this->assertSame(90.0, $jad['in_hand'], '120 collected − 30 handed, all time');
        $this->assertSame('2026-09-02', $jad['last_handover']);
        $this->assertSame(
            (int) Carbon::parse('2026-09-02')->diffInDays(now()->startOfDay()),
            $jad['since_days'],
            'how long he has been holding it decides who gets chased first',
        );

        $rami = $rows->firstWhere('collector_id', $this->rami->id);
        $this->assertSame(40.0, $rami['collected']);
        $this->assertSame(0.0, $rami['handed']);
        $this->assertSame(40.0, $rami['in_hand']);
        $this->assertNull($rami['last_handover'], 'he has never settled');
    }

    public function test_selecting_a_collector_opens_his_own_file(): void
    {
        $component = $this->screen($this->rami->id);

        $this->assertSame($this->rami->id, $component->instance()->collector()?->id);
        $component->assertViewHas('ledger', fn (array $ledger): bool => $ledger['totals']['closing'] === 40.0);
        $component->assertViewHas('documents', fn (array $documents): bool => $documents['rows_total'] === 1);
    }

    // ---- writing a settlement ----------------------------------------------

    public function test_a_settlement_larger_than_the_cash_in_hand_is_refused(): void
    {
        $component = $this->screen($this->jad->id)
            ->call('openHandover')
            ->assertSet('handoverOpen', true)
            // The modal opens on everything he holds, which is what the operator came to clear.
            ->assertSet('handover.amount', 90.0)
            ->set('handover.amount', 91)
            ->call('saveHandover')
            ->assertHasErrors('handover.amount');

        $component->assertSet('handoverOpen', true, 'the modal stays open with the typing intact');
        $this->assertSame(0, CashHandover::query()->where('amount', 91)->count());
    }

    public function test_a_settlement_is_written_from_the_screen_and_audited(): void
    {
        $this->screen($this->jad->id)
            ->call('openHandover')
            ->set('handover.amount', 30)
            ->set('handover.handover_date', '2026-09-05')
            ->set('handover.note', 'تصفية جزئية')
            ->call('saveHandover')
            ->assertSet('handoverOpen', false)
            ->assertHasNoErrors();

        $handover = CashHandover::query()->where('amount', 30)->firstOrFail();
        $this->assertSame($this->admin->id, (int) $handover->recorded_by);
        $this->assertSame(60.0, CollectionService::inHand($this->jad->id), '90 − 30');

        // The guard's own audit row, with the balance the office will reconcile against.
        $log = AuditLog::query()->where('action', 'handover.created')->where('subject_id', $handover->id)->firstOrFail();
        $this->assertSame(60.0, (float) $log->payload['in_hand_after']);
    }

    public function test_a_settlement_is_edited_and_deleted_from_the_same_screen(): void
    {
        $handover = CashHandover::query()->where('amount', 20)->firstOrFail();

        $component = $this->screen($this->jad->id)->call('openHandover', $handover->id);

        // Editing counts the record back in: he could settle the lot if the earlier entry was wrong.
        $component->assertSet('handover.amount', 20.0)
            ->assertViewHas('available', fn (float $available): bool => $available === 110.0);

        $component->set('handover.amount', 50)->call('saveHandover')->assertHasNoErrors();

        $this->assertSame(50.0, (float) $handover->fresh()->amount);
        $this->assertSame(60.0, CollectionService::inHand($this->jad->id), '120 collected − 10 − 50 handed over');
        $this->assertSame(1, AuditLog::query()->where('action', 'handover.updated')->count());

        $this->screen($this->jad->id)->call('deleteHandover', $handover->id);

        $this->assertNull(CashHandover::find($handover->id));
        $this->assertSame(110.0, CollectionService::inHand($this->jad->id), 'the amount is handed back');
        $this->assertSame(1, AuditLog::query()->where('action', 'handover.deleted')->count());
    }

    // ---- the auditing the office asked for ---------------------------------

    public function test_the_audit_table_groups_receipts_by_the_day_and_names_the_invoice(): void
    {
        $component = $this->screen($this->jad->id)->call('setTab', 'audit');

        $documents = $component->instance()->documents();
        $this->assertCount(1, $documents['days'], 'September holds one visit');
        $this->assertSame('2026-09-02', $documents['days'][0]['day']);
        $this->assertSame(60.0, $documents['days'][0]['total']);
        $this->assertSame(1, $documents['days'][0]['count']);

        $row = $documents['days'][0]['rows'][0];
        $this->assertSame('11:00', $row['time'], 'the moment, not just the day');
        $this->assertSame($this->jadSeptember->number, $row['number']);
        $this->assertSame('Area A', $row['route']);
        $this->assertSame([$this->jadSeptember->settledBills()->first()->number], $row['invoices']);

        $component
            ->assertSee(__('col.audit_day', ['date' => '2026-09-02', 'count' => 1, 'total' => money(60)]))
            ->assertSee($this->jadSeptember->number)
            ->assertSee('11:00')
            ->assertSee($row['method'])
            ->assertSee($row['recorder_label']);
    }

    public function test_the_recorder_is_named_from_the_trail_and_says_so_when_it_is_silent(): void
    {
        // The collector took it in the field; the office recorded Rami's at the counter.
        $documents = $this->screen($this->jad->id)->instance()->documents();
        $this->assertSame(__('col.recorder_collector'), $documents['days'][0]['rows'][0]['recorder_label']);

        $ramiDocuments = $this->screen($this->rami->id)->instance()->documents();
        $this->assertSame(
            __('col.recorder_office', ['name' => 'Admin']),
            $ramiDocuments['days'][0]['rows'][0]['recorder_label'],
        );

        // A receipt the trail never saw is not attributed to anyone.
        AuditLog::query()
            ->where('subject_type', Payment::class)
            ->where('subject_id', $this->jadSeptember->id)
            ->delete();

        $documents = $this->screen($this->jad->id)->instance()->documents();
        $this->assertSame(__('col.recorder_unknown'), $documents['days'][0]['rows'][0]['recorder_label']);
    }

    public function test_the_log_carries_the_balance_after_every_event_and_outlives_a_deletion(): void
    {
        $trail = $this->screen($this->jad->id)->instance()->trail();

        // Four movements, all recorded, ending where the drawer is.
        $this->assertSame(4, $trail['rows_total']);
        $this->assertSame(4, $trail['recorded']);
        $this->assertSame(90.0, $trail['closing'], 'the closing figure always equals the cash in hand');
        $this->assertSame(90.0, CollectionService::inHand($this->jad->id));

        // The oldest line carries the balance it left behind, not the final one.
        $oldest = $trail['rows'][count($trail['rows']) - 1];
        $this->assertSame(60.0, $oldest['balance']);

        // Now delete a settlement: its line must survive the record it describes.
        $handover = CashHandover::query()->where('amount', 20)->firstOrFail();
        $this->screen($this->jad->id)->call('deleteHandover', $handover->id);

        $after = $this->screen($this->jad->id)->instance()->trail();
        $kinds = array_column($after['rows'], 'kind');

        $this->assertContains('delete', $kinds, 'the deletion keeps its line');
        $this->assertSame(5, $after['rows_total']);
        $this->assertSame(110.0, $after['closing']);
        $this->assertSame(110.0, CollectionService::inHand($this->jad->id));
    }

    public function test_the_period_cut_carries_the_opening_balance_as_its_first_row(): void
    {
        $component = $this->screen($this->jad->id)->call('toggleCut');

        $component->assertSet('cutToPeriod', true)
            ->assertViewHas('ledger', fn (array $ledger): bool => $ledger['totals']['opening'] === 50.0
                && $ledger['totals']['closing'] === 90.0);

        // 60 collected and 10 handed over in August: that is where September starts from.
        $component
            ->assertSee(__('col.opening_row', ['date' => '2026-09-01']))
            ->assertSee(money(50))
            ->assertSee(__('col.cut_on'));

        // Un-cut, the ledger is the whole history and lands on the same closing figure.
        $component->call('toggleCut');
        $component->assertViewHas('ledger', fn (array $ledger): bool => $ledger['totals']['opening'] === 0.0
            && $ledger['totals']['closing'] === 90.0);
    }

    public function test_a_receipt_with_no_trail_line_is_still_listed_as_unrecorded(): void
    {
        AuditLog::query()
            ->where('subject_type', Payment::class)
            ->where('subject_id', $this->jadSeptember->id)
            ->delete();

        $trail = $this->screen($this->jad->id)->instance()->trail();

        $this->assertSame(4, $trail['rows_total'], 'the movement is not lost with its log line');
        $this->assertSame(1, $trail['unrecorded']);
        $this->assertSame(90.0, $trail['closing'], 'and the closing figure stays honest');
    }

    // ---- the screens this one replaced -------------------------------------

    public function test_the_old_three_routes_land_on_the_merged_screen(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/collector-statement?collector_id='.$this->rami->id.'&from=2026-09-01&to=2026-09-30');

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        // The collector and the range ride along, so a shared link lands on the same figures.
        $this->assertStringContainsString('collector_id='.$this->rami->id, $location);
        $this->assertStringContainsString('from=2026-09-01', $location);
        $this->assertStringContainsString('to=2026-09-30', $location);

        $this->get($location)->assertOk();

        $this->actingAs($this->admin)->get('/admin/collection-report')->assertRedirect();
        $this->actingAs($this->admin)->get('/admin/handovers')->assertRedirect();
        $this->actingAs($this->admin)
            ->get('/admin/handovers/create')
            ->assertRedirect(route('admin.collections', ['tab' => 'handovers']));
    }

    public function test_the_merged_screen_renders_for_an_admin_and_sends_guests_to_the_login(): void
    {
        $this->actingAs($this->admin)->get('/admin/collections')->assertOk()->assertSee('Jad Haddad');

        $this->app['auth']->logout();
        $this->get('/admin/collections')->assertRedirect(route('admin.login'));
    }

    public function test_the_two_printed_documents_are_still_reachable_from_the_screen(): void
    {
        $expected = route('print.collector-statement', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'collector_id' => $this->jad->id, 'size' => 'a4',
        ]);

        $this->screen($this->jad->id)->assertSee($expected);
        $this->screen()->assertSee(route('print.collection-report', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'size' => 'a4',
        ]));
    }

    public function test_every_control_is_wired_in_the_rendered_markup(): void
    {
        // A capability the view forgets to draw is not a capability — and a control whose handler is
        // misspelled looks perfect and does nothing. Both have bitten this panel before, so the
        // wiring itself is asserted where the browser reads it.
        $this->screen($this->jad->id)
            ->assertSee('wire:click="selectCollector('.$this->rami->id.')"', false)
            ->assertSee("wire:click=\"setTab('audit')\"", false)
            ->assertSee("wire:click=\"setTab('handovers')\"", false)
            ->assertSee("wire:click=\"setTab('log')\"", false)
            ->assertSee('wire:click="openHandover"', false)
            ->assertSee('wire:click="toggleCut"', false)
            // The period chips round-trip on their own.
            ->assertSee("wire:click=\"setPreset('last_month')\"", false);

        // The panes only exist once their tab is open — asserted where the browser would find them.
        $this->screen($this->jad->id)
            ->call('setTab', 'audit')
            ->assertSee('wire:model.live.debounce.300ms="auditSearch"', false)
            ->assertSee("wire:click=\"setTab('log')\"", false);

        $this->screen($this->jad->id)
            ->call('openHandover')
            ->assertSee('wire:submit="saveHandover"', false);
    }

    public function test_the_full_amount_is_accepted_and_nothing_is_refused(): void
    {
        // The ordinary end-of-day settlement: everything he holds, and the ledger lands on zero.
        $this->screen($this->jad->id)
            ->call('openHandover')
            ->set('handover.amount', 90)
            ->set('handover.handover_date', '2026-09-08')
            ->call('saveHandover')
            ->assertHasNoErrors();

        $this->assertSame(0.0, CollectionService::inHand($this->jad->id));

        // Nothing is not a settlement.
        $this->screen($this->jad->id)
            ->call('openHandover')
            ->set('handover.amount', 0)
            ->call('saveHandover')
            ->assertHasErrors('handover.amount');
    }

    public function test_the_delete_confirmation_states_what_comes_back_and_to_whom(): void
    {
        $handover = CashHandover::query()->where('amount', 20)->firstOrFail();
        $warning = __('col.delete_warning_row', ['amount' => money($handover->amount), 'name' => 'Jad Haddad']);

        // Deleting hands the money back; the warning has to say how much and whose hand, or the
        // operator is confirming a consequence they cannot see. From the row…
        $this->screen($this->jad->id)->call('setTab', 'handovers')->assertSee($warning);

        // …and from the modal, where the amount is the one being edited.
        $this->screen($this->jad->id)->call('openHandover', $handover->id)->assertSee($warning);
    }

    public function test_both_spreadsheets_carry_the_figures_on_screen(): void
    {
        $audit = $this->csv($this->screen($this->jad->id)->instance()->exportAudit());
        $this->assertStringContainsString('Collection audit', $audit);
        $this->assertStringContainsString($this->jadSeptember->number, $audit);
        $this->assertStringContainsString('60.00', $audit);

        $report = $this->csv($this->screen()->instance()->exportReport());
        $this->assertStringContainsString('Collected', $report);
        $this->assertStringContainsString('2026-09-02', $report);
        $this->assertStringContainsString('100.00', $report, 'the period total');
        $this->assertStringContainsString('130.00', $report, 'the cash position, all time');
    }

    /** Send the streamed response and read what it printed: the spreadsheet the office gets. */
    private function csv(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
