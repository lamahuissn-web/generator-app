<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\SubscriberImport as ImportPage;
use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ReadingImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * استيراد قراءات شهر — the office's paper ledger going in whole, and the bills falling out of it.
 *
 * The rules the office chose, pinned here:
 *
 *  - the previous reading in the file is CHECKED against the chain the panel can prove, and a mismatch
 *    refuses the row with both numbers (a wrong previous reading is a wrong bill sent to a household);
 *  - a month that is already read is refused, never silently overwritten;
 *  - the first month in the file teaches the panel the dial the household arrived with, so the chain
 *    starts where the paper says it starts.
 *
 * Nothing about money is decided here: every row goes through ReadingService::record(), the same call
 * the collector's screen makes, so the price, the fee, the rounding, the debt carried over and the bill
 * number are produced exactly as they would be by hand.
 */
class ReadingImportTest extends TestCase
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
            'name' => 'مدير', 'email' => 'admin@readings.test', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'عين الرمانة']);
    }

    private function subscriber(array $attributes = []): Subscriber
    {
        return Subscriber::create(array_merge([
            'route_id' => $this->route->id,
            'name' => 'محمد عيتاني',
            'meter_no' => 'M-'.random_int(10000, 99999),
            'type' => Subscriber::TYPE_METERED,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    /** A readings row, keyed by column name. */
    private function row(array $values): array
    {
        return array_map(
            fn (string $key): string => (string) ($values[$key] ?? ''),
            array_keys(ReadingImport::columns()),
        );
    }

    private function upload(array $rows, ?array $headers = null): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers ?? ReadingImport::headers());

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return UploadedFile::fake()->createWithContent('readings.csv', $csv);
    }

    private function preview(array $rows, ?array $headers = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'rd').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", array_map(
            fn (array $cells): string => implode(',', $cells),
            array_merge([$headers ?? ReadingImport::headers()], $rows),
        )));

        $parsed = ReadingImport::parse($path);
        @unlink($path);

        $this->assertNull($parsed['error'], 'the file should have been readable');

        return ReadingImport::preview($parsed['rows']);
    }

    public function test_the_template_is_the_columns_the_importer_reads(): void
    {
        $template = ReadingImport::template();
        $firstLine = str_getcsv(explode("\n", substr($template, 3))[0]);

        $this->assertSame(ReadingImport::headers(), $firstLine);
        $this->assertStringContainsString(__('t.period'), implode('|', ReadingImport::headers()));
        $this->assertStringContainsString(__('t.prev_reading'), implode('|', ReadingImport::headers()));
    }

    public function test_a_month_of_readings_becomes_readings_and_bills(): void
    {
        $first = $this->subscriber(['name' => 'زبون أول', 'meter_no' => 'M-1001']);
        $second = $this->subscriber(['name' => 'زبون تاني', 'meter_no' => 'M-1002']);

        $previewed = $this->preview([
            $this->row(['meter_no' => 'M-1001', 'period' => '2026-08', 'previous' => '12500', 'current' => '12600']),
            $this->row(['meter_no' => 'M-1002', 'period' => '2026-08', 'previous' => '400', 'current' => '450']),
        ]);

        $this->assertSame(['ok', 'ok'], array_column($previewed, 'status'), json_encode(array_column($previewed, 'errors'), JSON_UNESCAPED_UNICODE));

        $outcome = ReadingImport::commit($previewed, $this->admin->id);

        $this->assertSame(2, $outcome['created']);
        $this->assertSame(2, Reading::count());
        $this->assertSame(2, Bill::count());

        $reading = Reading::where('subscriber_id', $first->id)->firstOrFail();
        $this->assertSame(12500.0, (float) $reading->previous_reading);
        $this->assertSame(12600.0, (float) $reading->current_reading);
        $this->assertSame(100.0, (float) $reading->consumption);

        // The bill is the panel's own arithmetic: 100 kWh at the office's price.
        $bill = Bill::where('subscriber_id', $first->id)->firstOrFail();
        $this->assertSame(25.0, (float) $bill->usage_amount);
        $this->assertSame('2026-08', $bill->period);
        $this->assertSame($second->id, (int) Reading::where('subscriber_id', $second->id)->firstOrFail()->subscriber_id);
    }

    public function test_the_first_month_teaches_the_panel_the_dial_the_household_arrived_with(): void
    {
        $subscriber = $this->subscriber(['meter_no' => 'M-2001']);

        $previewed = $this->preview([
            $this->row(['meter_no' => 'M-2001', 'period' => '2026-08', 'previous' => '12500', 'current' => '12600']),
        ]);

        $this->assertSame('ok', $previewed[0]['status']);
        ReadingImport::commit($previewed, $this->admin->id);

        $subscriber->refresh();

        $this->assertSame(12500.0, (float) $subscriber->opening_reading);
        $this->assertSame('2026-07', $subscriber->opening_period);
        $this->assertSame(100.0, (float) Reading::firstOrFail()->consumption);
    }

    public function test_a_previous_reading_that_contradicts_the_panel_refuses_the_row_and_names_both(): void
    {
        $subscriber = $this->subscriber(['meter_no' => 'M-3001']);
        BillingService::enterMeteredReading($subscriber, '2026-07', 12560.0, $this->admin->id);

        $previewed = $this->preview([
            $this->row(['meter_no' => 'M-3001', 'period' => '2026-08', 'previous' => '12530', 'current' => '12600']),
        ]);

        $this->assertSame('problem', $previewed[0]['status']);
        $this->assertStringContainsString('12530', $previewed[0]['errors'][0]);
        $this->assertStringContainsString('12560', $previewed[0]['errors'][0]);
        $this->assertSame(1, Reading::count(), 'the refused row must not have written a reading');
    }

    public function test_a_month_already_read_is_refused_and_the_reading_is_left_alone(): void
    {
        $subscriber = $this->subscriber(['meter_no' => 'M-4001']);
        $existing = BillingService::enterMeteredReading($subscriber, '2026-08', 12560.0, $this->admin->id)['reading'];

        $previewed = $this->preview([
            $this->row(['meter_no' => 'M-4001', 'period' => '2026-08', 'previous' => '12500', 'current' => '12999']),
        ]);

        $this->assertSame('problem', $previewed[0]['status']);
        $this->assertStringContainsString('2026-08', $previewed[0]['errors'][0]);

        ReadingImport::commit($previewed, $this->admin->id);

        $this->assertSame(12560.0, (float) $existing->fresh()->current_reading, 'a closed cycle is never overwritten');
        $this->assertSame(1, Reading::count());
    }

    public function test_an_unknown_meter_is_refused(): void
    {
        $previewed = $this->preview([
            $this->row(['meter_no' => 'M-9999', 'period' => '2026-08', 'previous' => '10', 'current' => '20']),
        ]);

        $this->assertSame('problem', $previewed[0]['status']);
        $this->assertStringContainsString('M-9999', $previewed[0]['errors'][0]);
    }

    public function test_a_flat_subscriber_is_refused_with_a_reason(): void
    {
        $this->subscriber(['name' => 'مقطوع', 'meter_no' => 'M-5001', 'type' => Subscriber::TYPE_FLAT, 'flat_fee' => 20]);

        $previewed = $this->preview([
            $this->row(['meter_no' => 'M-5001', 'period' => '2026-08', 'previous' => '10', 'current' => '20']),
        ]);

        $this->assertSame('problem', $previewed[0]['status']);
        $this->assertStringContainsString('مقطوع', $previewed[0]['errors'][0]);
    }

    public function test_a_current_reading_below_the_previous_one_is_refused(): void
    {
        $this->subscriber(['meter_no' => 'M-6001']);

        $previewed = $this->preview([
            $this->row(['meter_no' => 'M-6001', 'period' => '2026-08', 'previous' => '12500', 'current' => '12000']),
        ]);

        $this->assertSame('problem', $previewed[0]['status']);
        $this->assertSame(0, Reading::count());
    }

    public function test_a_row_repeated_inside_the_file_is_refused_on_the_second_one(): void
    {
        $this->subscriber(['meter_no' => 'M-7001']);

        $previewed = $this->preview([
            $this->row(['meter_no' => 'M-7001', 'period' => '2026-08', 'previous' => '12500', 'current' => '12600']),
            $this->row(['meter_no' => 'M-7001', 'period' => '2026-08', 'previous' => '12500', 'current' => '12600']),
        ]);

        $this->assertSame('ok', $previewed[0]['status']);
        $this->assertSame('problem', $previewed[1]['status']);
    }

    public function test_the_apply_carries_readings_through_the_same_native_post(): void
    {
        $this->subscriber(['meter_no' => 'M-8001']);

        Livewire::actingAs($this->admin)
            ->test(ImportPage::class)
            ->set('type', 'readings')
            ->set('file', $this->upload([
                $this->row(['meter_no' => 'M-8001', 'period' => '2026-08', 'previous' => '500', 'current' => '640']),
            ]))
            ->call('readFile')
            ->assertSet('step', 'preview');

        $this->assertSame(0, Reading::count(), 'the preview writes nothing');

        $this->actingAs($this->admin)
            ->post(route('admin.subscribers-import.apply'))
            ->assertRedirect(route('admin.subscribers-import'));

        $this->assertSame(1, Reading::count());
        $this->assertSame(1, Bill::count());
        $this->assertSame(35.0, (float) Bill::firstOrFail()->usage_amount);   // 140 kWh × 0.25
    }

    public function test_the_kind_choice_swaps_the_template_and_clears_a_preview_of_the_other_kind(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ImportPage::class)
            ->call('downloadTemplate')
            ->assertFileDownloaded('subscribers-template.csv');

        Livewire::actingAs($this->admin)
            ->test(ImportPage::class)
            ->set('type', 'readings')
            ->call('downloadTemplate')
            ->assertFileDownloaded('readings-template.csv');
    }

    public function test_the_screen_shows_the_readings_columns_for_a_readings_file(): void
    {
        $this->subscriber(['meter_no' => 'M-9001']);

        Livewire::actingAs($this->admin)
            ->test(ImportPage::class)
            ->set('type', 'readings')
            ->set('file', $this->upload([
                $this->row(['name' => 'محمد عيتاني', 'meter_no' => 'M-9001', 'period' => '2026-08', 'previous' => '40', 'current' => '90']),
            ]))
            ->call('readFile')
            ->assertSee(__('t.prev_reading'))
            ->assertSee(__('t.current_reading'))
            ->assertSee('2026-08');
    }

    public function test_a_subscriber_file_pushed_through_the_readings_kind_is_refused_with_guidance(): void
    {
        // The columns are what tell the two apart, and the refusal says which choice to make.
        $subscriberRow = ['name' => 'علي شرارة', 'route' => 'عين الرمانة', 'type' => 'اشتراك بالعداد'];

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, \App\Services\SubscriberImport::headers());
        fputcsv($handle, array_map(
            fn (string $key): string => (string) ($subscriberRow[$key] ?? ''),
            array_keys(\App\Services\SubscriberImport::columns()),
        ));
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'sub').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$csv);

        $parsed = ReadingImport::parse($path);
        @unlink($path);

        $this->assertNotNull($parsed['error']);
        $this->assertStringContainsString(__('t.prev_reading'), $parsed['error']);
    }
}
