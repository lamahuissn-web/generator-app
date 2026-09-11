<?php

namespace Tests\Feature;

use App\Admin\Tables\AuditLogsTable;
use App\Admin\Tables\ReadingsTable;
use App\Livewire\Admin\DataTable;
use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The office's readings list.
 *
 * The screen's job is to be ANSWERABLE: which area, which collector, which meter — and then the
 * whole filtered set out as a spreadsheet. Each of those is asserted here, because a filter that
 * silently does nothing (or a CSV that ignores the filters) looks exactly like a working one
 * until somebody reconciles a month by hand.
 */
class ReadingsListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $firstCollector;

    private User $secondCollector;

    private Route $route;

    private Route $otherRoute;

    private Subscriber $first;

    private Subscriber $second;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->period = BillingService::currentPeriod();

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@list.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->firstCollector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@list.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);
        $this->secondCollector = User::create([
            'name' => 'Rami Nasser', 'email' => 'rami@list.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'Ain El Remmaneh']);
        $this->otherRoute = Route::create(['name' => 'Tariq El Jdideh']);

        $this->first = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'محمد عيتاني',
            'phone' => '03 555789', 'meter_no' => '1123',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);
        $this->second = Subscriber::create([
            'route_id' => $this->otherRoute->id, 'name' => 'حسن عواد',
            'phone' => '76 888999', 'meter_no' => '3301',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);
    }

    /** One recorded cycle for a house, with the bill the service would have raised. */
    private function reading(Subscriber $subscriber, string $period, float $value, float $consumption, ?User $by = null): Reading
    {
        return BillingService::enterMeteredReading(
            $subscriber, $period, $value, ($by ?? $this->firstCollector)->id,
        )['reading'];
    }

    private function table(array $filters = [], string $search = ''): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->admin)
            ->test(DataTable::class, ['table' => ReadingsTable::class])
            ->set('filters', $filters)
            ->set('search', $search);
    }

    /** @return array<int, string> the subscriber names the table currently shows */
    private function names(\Livewire\Features\SupportTesting\Testable $component): array
    {
        return collect($component->instance()->rows()->items())
            ->map(fn ($row) => $row->subscriber?->name)
            ->filter()
            ->values()
            ->all();
    }

    // ---- the screen -----------------------------------------------------------

    public function test_the_list_offers_area_collector_period_and_source_filters(): void
    {
        $this->reading($this->first, $this->period, 1400, 60);

        $this->actingAs($this->admin)
            ->get(route('admin.readings.index'))
            ->assertOk()
            // "الفترة — الكل" style labels: one select per filter, each with an all option.
            ->assertSee('المنطقة — الكل')
            ->assertSee('سُجل بواسطة — الكل')
            ->assertSee('مصدر القراءة — الكل')
            ->assertSee('تصدير CSV');
    }

    public function test_the_screen_points_at_the_two_questions_it_cannot_answer(): void
    {
        // One metered house with no reading this cycle, and one dead meter.
        $this->reading($this->first, $this->period, 1400, 60, $this->firstCollector);
        $this->second->reportMeterBroken('العدّاد معطّل', $this->firstCollector->id);

        $this->actingAs($this->admin)
            ->get(route('admin.readings.index'))
            ->assertOk()
            ->assertSee('بلا قراءة هالدورة')
            ->assertSee('عدّادات معطّلة')
            ->assertSee(route('admin.readings-board'))
            ->assertSee(route('admin.meter-cases'));
    }

    public function test_a_metered_house_with_no_reading_this_cycle_is_counted_in_the_header(): void
    {
        // Two metered houses, one read: the header must say one is still missing.
        $this->reading($this->first, $this->period, 1400, 60);

        $this->actingAs($this->admin)
            ->get(route('admin.readings.index'))
            ->assertOk()
            ->assertSee('مسجّلة 1 من 2');
    }

    public function test_a_row_opens_its_house_and_its_bill(): void
    {
        $reading = $this->reading($this->first, $this->period, 1400, 60);

        $component = $this->table();
        $row = $component->instance()->rows()->items()[0];

        // The name links to the subscriber…
        $this->assertSame(
            route('admin.subscribers.show', $this->first->id),
            $component->instance()->definition()->rowUrl($row),
        );

        // …and the row offers the invoice it produced (the bill points at the reading, not the
        // other way round, so this is where a null bill_id used to break the whole screen).
        $actions = $component->instance()->definition()->rowActions();
        $billAction = collect($actions)->firstWhere('key', 'bill');
        $this->assertSame(
            route('print.bill', ['bill' => $reading->bill->id, 'size' => config('pdf.default_bill_size')]),
            ($billAction['url'])($row),
        );
        $this->assertFalse(($billAction['hidden'])($row));
    }

    public function test_a_baseline_reading_offers_no_bill_to_open(): void
    {
        $baseline = BillingService::recordBaselineReading($this->first, $this->period, 900, $this->admin->id);

        $actions = ReadingsTable::make()->rowActions();
        $billAction = collect($actions)->firstWhere('key', 'bill');

        $this->assertNull($baseline->bill);
        $this->assertTrue(($billAction['hidden'])($baseline));
    }

    // ---- filters and search ---------------------------------------------------

    public function test_the_area_filter_narrows_the_list(): void
    {
        $this->reading($this->first, $this->period, 1400, 60);
        $this->reading($this->second, $this->period, 131, 31);

        $this->assertCount(2, $this->table()->instance()->rows()->items());
        $this->assertCount(1, $this->table(['route_id' => $this->otherRoute->id])->instance()->rows()->items());
        $this->assertSame(
            ['حسن عواد'],
            $this->names($this->table(['route_id' => $this->otherRoute->id])),
        );
    }

    public function test_the_collector_filter_answers_who_recorded_what(): void
    {
        $this->reading($this->first, $this->period, 1400, 60, $this->firstCollector);
        $this->reading($this->second, $this->period, 131, 31, $this->secondCollector);

        $this->assertSame(['محمد عيتاني'], $this->names($this->table(['entered_by' => $this->firstCollector->id])));
        $this->assertSame(['حسن عواد'], $this->names($this->table(['entered_by' => $this->secondCollector->id])));
    }

    public function test_the_period_filter_combines_with_the_area_filter(): void
    {
        $this->reading($this->first, $this->period, 1400, 60);
        $this->reading($this->second, $this->period, 131, 31);
        $lastMonth = now()->startOfMonth()->subMonth()->format('Y-m');
        $this->reading($this->first, $lastMonth, 1340, 70);

        $both = ['route_id' => $this->route->id, 'period' => $lastMonth];

        $this->assertSame(['محمد عيتاني'], $this->names($this->table($both)));
        $this->assertCount(1, $this->table($both)->instance()->rows()->items());
    }

    public function test_the_search_box_finds_a_subscriber_by_meter_number_or_phone(): void
    {
        $this->reading($this->first, $this->period, 1400, 60);
        $this->reading($this->second, $this->period, 131, 31);

        // Over the phone the office has a meter number, not a name.
        $this->assertSame(['محمد عيتاني'], $this->names($this->table(search: '1123')));
        // …or the number the subscriber is calling from.
        $this->assertSame(['حسن عواد'], $this->names($this->table(search: '888999')));
        // The name still works.
        $this->assertSame(['حسن عواد'], $this->names($this->table(search: 'حسن')));
    }

    public function test_the_estimated_filter_keeps_its_meaning(): void
    {
        $this->reading($this->first, $this->period, 1400, 60);
        BillingService::enterEstimatedReading($this->second, $this->period, 40, 'ما حدا فتح', $this->admin->id);

        $this->assertSame(['حسن عواد'], $this->names($this->table(['estimated' => '1'])));
        $this->assertSame(['محمد عيتاني'], $this->names($this->table(['estimated' => '0'])));
    }

    // ---- the export -----------------------------------------------------------

    /** The streamed CSV, as the browser would receive it. */
    private function csv(\Livewire\Features\SupportTesting\Testable $component): array
    {
        $response = $component->instance()->exportCsv();

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        return [$body, $response->headers->get('content-disposition')];
    }

    public function test_the_export_carries_the_list_with_a_bom_and_a_period_in_its_name(): void
    {
        $this->reading($this->first, $this->period, 1400, 60, $this->firstCollector);

        [$body, $disposition] = $this->csv($this->table());

        // The BOM is what makes Excel read Arabic correctly…
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        // …and the period is in the file name, because the office keeps a file per cycle.
        $this->assertStringContainsString('.csv', (string) $disposition);

        // Parsed without the BOM: it rides in front of the first heading, not inside it.
        $lines = explode("\n", substr($body, 3));
        $header = str_getcsv($lines[0]);
        $this->assertSame(
            ['المشترك', 'المنطقة', 'العداد', 'الفترة', 'السابقة', 'الحالية', 'ك.و.س', 'مصدر القراءة', 'سُجل بواسطة'],
            array_slice($header, 0, 9),
        );

        $row = str_getcsv($lines[1]);
        $this->assertSame('محمد عيتاني', $row[0]);
        $this->assertSame('Ain El Remmaneh', $row[1]);
        $this->assertSame('1123', $row[2]);
        $this->assertSame($this->period, $row[3]);
        // Raw numbers, so the spreadsheet can add them up: a first reading continues from 0.
        $this->assertSame('0', $row[4]);
        $this->assertSame('1400', $row[5]);
        $this->assertSame('1400.0', $row[6]);
        $this->assertSame('فعلية', $row[7]);
        $this->assertSame('Jad Haddad', $row[8]);
        $this->assertNotSame('', $row[9], 'the invoice number travels with the reading');
        $this->assertNotSame('', $row[10], 'and so does what the invoice is worth');
    }

    public function test_the_export_respects_the_filters_and_the_search(): void
    {
        $this->reading($this->first, $this->period, 1400, 60);
        $this->reading($this->second, $this->period, 131, 31);

        [$filtered] = $this->csv($this->table(['route_id' => $this->otherRoute->id]));
        $this->assertStringContainsString('حسن عواد', $filtered);
        $this->assertStringNotContainsString('محمد عيتاني', $filtered);

        [$searched] = $this->csv($this->table(search: '1123'));
        $this->assertStringContainsString('محمد عيتاني', $searched);
        $this->assertStringNotContainsString('حسن عواد', $searched);
    }

    public function test_a_list_without_an_export_says_so_instead_of_downloading_an_empty_file(): void
    {
        $component = Livewire::actingAs($this->admin)->test(DataTable::class, ['table' => AuditLogsTable::class]);

        $this->assertSame([], AuditLogsTable::make()->csvHeader(), 'the audit log has no export by default');
        $this->assertNull($component->instance()->exportCsv());
        // The operator gets a message where they clicked, not a silent no-op.
        $component->call('exportCsv')->assertSee('ما فيها تصدير');
    }

    /**
     * Every row action used to write its outcome to the SESSION, and this table renders inside a
     * page — so the message only appeared on the next full page load. The operator watched a
     * reading disappear with no word about why, and a refused action said nothing at all.
     */
    public function test_a_row_action_announces_its_outcome_where_it_happened(): void
    {
        $reading = $this->reading($this->first, $this->period, 1400, 60);
        $billId = $reading->bill->id;

        $this->table()
            ->call('runAction', 'deleteReading', $reading->id)
            ->assertSet('flash', __('a.del_reading_ok'))
            ->assertSet('flashType', 'success')
            ->assertSee(__('a.del_reading_ok'));

        $this->assertSame(0, Reading::whereKey($reading->id)->count());
        // The bill the reading produced goes with it — "delete the reading" is not a half-measure.
        $this->assertSame(0, Bill::whereKey($billId)->count());
    }

    public function test_a_refused_row_action_shows_the_reason_instead_of_a_bare_refusal(): void
    {
        // A paid bill cannot be undone by deleting its reading: the service refuses, and the
        // operator must be told which payment is in the way.
        $reading = $this->reading($this->first, $this->period, 1400, 60);
        Bill::whereKey($reading->bill->id)->update(['paid_amount' => 10]);

        $this->table()
            ->call('runAction', 'deleteReading', $reading->id)
            ->assertSet('flashType', 'error');

        $this->assertSame(1, Reading::whereKey($reading->id)->count());
    }
}
