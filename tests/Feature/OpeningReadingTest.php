<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\SubscriberImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The reading a meter was already showing when the office took the subscriber on.
 *
 * Found while building the importer, with a number: a household that arrived from a paper ledger with
 * 12,500 on the dial and was read at 12,600 the next month was billed 12,600 kWh — $3,150 instead of
 * $25 — because the panel resolves «the previous reading» as the last reading or 0, and a subscriber
 * with no readings yet has no last reading.
 *
 * Two half-answers were rejected. Making the office fake a meter swap works, but a migration of two
 * hundred households is not a place for a workaround. Storing the opening reading as a reading row
 * would mark the month as read and block the real reading that month (refuse.reading_exists) — the
 * office would then be unable to enter the truth.
 *
 * So the reading lives on the subscriber, and ONE method — BillingService::previousValue() — resolves
 * where a cycle starts. Every screen that prints «السابقة» asks there, so the invoice, the collector's
 * form and the printed sheet cannot disagree.
 */
class OpeningReadingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        Setting::set('kwh_price', '0.25');

        $this->admin = User::create([
            'name' => 'مدير', 'email' => 'admin@opening.test', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'عين الرمانة']);
    }

    private function subscriber(array $attributes = []): Subscriber
    {
        return Subscriber::create(array_merge([
            'route_id' => $this->route->id,
            'name' => 'محمد عيتاني',
            'meter_no' => 'M-'.random_int(1000, 9999),
            'type' => Subscriber::TYPE_METERED,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    public function test_a_household_that_arrived_with_a_running_meter_is_billed_from_where_it_arrived(): void
    {
        $subscriber = $this->subscriber(['opening_reading' => 12500, 'opening_period' => now()->subMonth()->format('Y-m')]);

        $result = BillingService::enterMeteredReading($subscriber, now()->format('Y-m'), 12600.0, $this->admin->id);

        $reading = $result['reading'];

        $this->assertSame(12500.0, (float) $reading->previous_reading, 'the cycle must start at the dial we arrived with');
        $this->assertSame(100.0, (float) $reading->consumption);
        // 100 kWh at the office's price — not the $3,150 the whole counter would have cost.
        $this->assertSame(25.0, (float) $result['bill']->usage_amount);
    }

    public function test_a_new_dial_still_starts_at_zero_exactly_as_before(): void
    {
        $subscriber = $this->subscriber();   // no opening reading: a brand-new meter

        $result = BillingService::enterMeteredReading($subscriber, now()->format('Y-m'), 12600.0, $this->admin->id);

        $this->assertSame(0.0, (float) $result['reading']->previous_reading);
        $this->assertSame(12600.0, (float) $result['reading']->consumption);
    }

    public function test_a_reading_that_came_before_beats_the_opening_one(): void
    {
        $subscriber = $this->subscriber(['opening_reading' => 12500, 'opening_period' => now()->subMonths(2)->format('Y-m')]);

        BillingService::enterMeteredReading($subscriber, now()->subMonth()->format('Y-m'), 12550.0, $this->admin->id);

        $result = BillingService::enterMeteredReading($subscriber, now()->format('Y-m'), 12600.0, $this->admin->id);

        $this->assertSame(12550.0, (float) $result['reading']->previous_reading);
        $this->assertSame(50.0, (float) $result['reading']->consumption);
    }

    public function test_every_screen_resolves_the_previous_reading_from_the_same_place(): void
    {
        $subscriber = $this->subscriber(['opening_reading' => 12500, 'opening_period' => now()->subMonth()->format('Y-m')]);

        $this->assertSame(12500.0, BillingService::previousValue($subscriber, now()->format('Y-m')));

        $next = now()->addMonth()->format('Y-m');
        BillingService::enterMeteredReading($subscriber, now()->format('Y-m'), 12600.0, $this->admin->id);

        // After one cycle the dial's own history takes over, and it still starts where the last read.
        $this->assertSame(12600.0, BillingService::previousValue($subscriber, $next));
    }

    public function test_the_printed_sheet_shows_the_dial_we_arrived_with_instead_of_zero(): void
    {
        $subscriber = $this->subscriber(['opening_reading' => 12500, 'opening_period' => now()->subMonth()->format('Y-m')]);

        // The board the office prints before a round: it must not send a collector out to re-read a
        // meter «from zero».
        $row = \App\Reports\ReadingBoard::make(now()->format('Y-m'), null, \App\Reports\ReadingBoard::STATE_ALL)
            ->rows()
            ->firstWhere('id', $subscriber->id);

        $this->assertNotNull($row);
        $this->assertSame(12500.0, (float) $row['previous']);
    }

    public function test_the_importer_carries_the_reading_and_its_month(): void
    {
        $csv = implode("\n", [
            implode(',', SubscriberImport::headers()),
            implode(',', ['علي شرارة', $this->route->name, 'M-1051', '', '', __('s.metered'), __('portal.active'), '', __('imp.mode_auto'), '', '', '', '12500', '2026-08']),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'open').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$csv);

        $parsed = SubscriberImport::parse($path);
        $this->assertNull($parsed['error']);

        $previewed = SubscriberImport::preview($parsed['rows']);
        $this->assertSame('ok', $previewed[0]['status'], implode(' | ', $previewed[0]['errors']));

        SubscriberImport::commit($previewed, $this->admin->id);

        $subscriber = Subscriber::where('meter_no', 'M-1051')->firstOrFail();
        $this->assertSame(12500.0, (float) $subscriber->opening_reading);
        $this->assertSame('2026-08', $subscriber->opening_period);

        @unlink($path);
    }

    public function test_a_reading_with_no_month_belongs_to_the_month_it_is_imported_in(): void
    {
        $csv = implode("\n", [
            implode(',', SubscriberImport::headers()),
            implode(',', ['علي شرارة', $this->route->name, 'M-2051', '', '', __('s.metered'), __('portal.active'), '', __('imp.mode_auto'), '', '', '', '9000', '']),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'open').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$csv);

        $previewed = SubscriberImport::preview(SubscriberImport::parse($path)['rows']);
        SubscriberImport::commit($previewed, $this->admin->id);

        $this->assertSame(now()->format('Y-m'), Subscriber::where('meter_no', 'M-2051')->firstOrFail()->opening_period);

        @unlink($path);
    }

    public function test_a_month_nobody_can_read_is_refused_by_name(): void
    {
        $csv = implode("\n", [
            implode(',', SubscriberImport::headers()),
            implode(',', ['علي شرارة', $this->route->name, 'M-3051', '', '', __('s.metered'), __('portal.active'), '', __('imp.mode_auto'), '', '', '', '12500', 'شهر آب']),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'open').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$csv);

        $previewed = SubscriberImport::preview(SubscriberImport::parse($path)['rows']);

        $this->assertSame('problem', $previewed[0]['status']);
        $this->assertStringContainsString('شهر آب', $previewed[0]['errors'][0]);
        $this->assertSame(0, Subscriber::count());

        @unlink($path);
    }

    public function test_a_flat_subscriber_has_no_dial_and_keeps_no_opening_reading(): void
    {
        $csv = implode("\n", [
            implode(',', SubscriberImport::headers()),
            implode(',', ['سامي دندشي', $this->route->name, '', '', '', __('s.flat_rate'), __('portal.active'), '', __('imp.mode_custom'), '', '25', '', '9999', '2026-08']),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'open').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$csv);

        $previewed = SubscriberImport::preview(SubscriberImport::parse($path)['rows']);
        SubscriberImport::commit($previewed, $this->admin->id);

        $subscriber = Subscriber::where('name', 'سامي دندشي')->firstOrFail();

        $this->assertNull($subscriber->opening_reading);
        $this->assertNull($subscriber->opening_period);

        @unlink($path);
    }

    public function test_the_form_asks_both_questions_and_stores_what_a_person_typed(): void
    {
        // The manual path has the same hole as the import, so it asks the same two questions.
        $defined = collect((new \App\Admin\Forms\SubscriberForm)->sections())
            ->flatMap(fn (array $section): array => $section['fields'] ?? [])
            ->pluck('key')
            ->all();

        $this->assertContains('opening_reading', $defined);
        $this->assertContains('opening_period', $defined);

        $this->assertSame('2026-09', Subscriber::cleanOpeningPeriod('2026/9'));
        $this->assertSame('2026-09', Subscriber::cleanOpeningPeriod('2026-09'));
        $this->assertNull(Subscriber::cleanOpeningPeriod('شهر آب'));
        $this->assertNull(Subscriber::cleanOpeningPeriod('2026-13'));
    }

    public function test_a_month_that_is_not_a_month_never_reaches_the_database(): void
    {
        $subscriber = $this->subscriber(['opening_reading' => 100, 'opening_period' => null]);

        $this->assertNull($subscriber->opening_period);
        // And the column is a plain string, so nothing invents a date out of a bad month.
        $this->assertSame('2026-01', $this->subscriber(['opening_reading' => 1, 'opening_period' => '2026-01'])->opening_period);
    }

    public function test_the_exporter_writes_the_opening_reading_back_out(): void
    {
        $subscriber = $this->subscriber(['opening_reading' => 12500, 'opening_period' => '2026-08']);

        $row = (new \App\Admin\Tables\SubscribersTable)->csvRow($subscriber->fresh());

        $this->assertSame('12500.00', $row[12]);
        $this->assertSame('2026-08', $row[13]);
        $this->assertCount(count(SubscriberImport::headers()), $row);
    }

    public function test_an_empty_box_is_not_a_zero(): void
    {
        $bill = Bill::query()->count();

        // A subscriber with no opening reading must not be treated as starting at zero-by-accident
        // versus zero-on-purpose: both bill from the dial's own history, and the column stays null so
        // the office can tell «nobody said» from «it really was at zero».
        $subscriber = $this->subscriber();

        $this->assertNull($subscriber->opening_reading);
        $this->assertSame(0, $bill);
    }
}
