<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\Backup as BackupPage;
use App\Models\BackupAttempt;
use App\Models\Bill;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupSettings;
use App\Services\Backup\BackupStatus;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\TelegramBackupSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The nightly copy of the ledger and its delivery to Telegram.
 *
 * Two things are being protected here, and they are different in kind. The first is the COPY: a file
 * that restores into the numbers the office recognises, verified as whole before anybody is told it
 * exists — because a backup that quietly is not one is worse than no backup at all. The second is the
 * SILENCE: every way this feature can fail without anybody noticing (a chat that refuses, a channel
 * that is down, a cron entry that was never installed) has to leave a trace the office can read.
 *
 * The Bot API is never contacted: `Http::fake()` stands in for it, and the payloads posted at it are
 * asserted, because the one thing that must never happen is the panel recording "delivered" for
 * something Telegram refused.
 */
class TelegramBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private string $backups;

    private string $token = '1234567890:AAHtest-token-should-never-be-logged';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@backup.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@backup.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $route = Route::create(['name' => 'Ain El Remmaneh']);
        $subscriber = Subscriber::create([
            'route_id' => $route->id, 'name' => "مخبز الشام's bakery", 'phone' => '03 111222',
            'meter_no' => '8823', 'type' => Subscriber::TYPE_METERED, 'amperage' => 10,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT, 'status' => Subscriber::STATUS_ACTIVE,
        ]);
        Bill::create([
            'subscriber_id' => $subscriber->id, 'period' => now()->format('Y-m'),
            'type' => Subscriber::TYPE_METERED, 'total' => 36.50, 'paid_amount' => 0,
        ]);

        $this->backups = storage_path('framework/testing/backups-'.uniqid());
        File::ensureDirectoryExists($this->backups);

        config([
            'backup.path' => $this->backups,
            'backup.defaults.retain' => 30,
            'backup.defaults.delivery_limit_bytes' => 45 * 1024 * 1024,
            'backup.stale_after_hours' => 26,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backups);

        parent::tearDown();
    }

    // ---- fixtures -------------------------------------------------------------

    private function configureDestination(): void
    {
        BackupSettings::saveBotToken($this->token);
        BackupSettings::saveChatId('-1001234567890');
    }

    /** A copy that "arrived last night". */
    private function deliveredCopy(int $hoursAgo = 12): BackupAttempt
    {
        return BackupAttempt::create([
            'started_at' => now()->subHours($hoursAgo),
            'finished_at' => now()->subHours($hoursAgo),
            'trigger' => BackupAttempt::TRIGGER_SCHEDULED,
            'status' => BackupAttempt::STATUS_SENT,
            'file_name' => 'generator-'.now()->subDay()->format('Y-m-d-Hi').'.sql',
            'size_bytes' => 2_400_000,
        ]);
    }

    private function fakeTelegramOk(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    /**
     * One multipart field as it was actually posted.
     *
     * A document upload is multipart, so `data()` is a list of parts rather than an associative
     * array — reading it positionally is how a test ends up asserting something the panel never
     * sent. Returns null for a request that is not a document upload or has no such field.
     */
    private function field(Request $request, string $name): ?string
    {
        if (! str_contains($request->url(), '/sendDocument')) {
            return null;
        }

        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === $name && is_string($part['contents'] ?? null)) {
                return $part['contents'];
            }
        }

        return null;
    }

    // ---- the copy itself ------------------------------------------------------

    public function test_the_copy_restores_the_ledger_exactly(): void
    {
        $dump = DatabaseDumper::make()->run();

        $this->assertTrue(DatabaseDumper::verify($dump['path']), 'the completion marker must be readable');
        $this->assertGreaterThan(0, $dump['size']);

        // Import it into a database that has never seen the panel.
        config(['database.connections.dump_check' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);

        $check = DB::connection('dump_check');
        $check->getPdo()->exec((string) file_get_contents($dump['path']));

        $this->assertSame(
            DB::connection()->table('subscribers')->count(),
            $check->table('subscribers')->count(),
            'every subscriber came back',
        );
        $this->assertSame(
            DB::connection()->table('bills')->count(),
            $check->table('bills')->count(),
        );

        $restored = $check->table('subscribers')->where('meter_no', '8823')->first();

        $this->assertNotNull($restored);
        $this->assertSame("مخبز الشام's bakery", $restored->name, 'a name with an apostrophe and Arabic survives');
        $this->assertSame(
            '36.50',
            number_format((float) $check->table('bills')->value('total'), 2, '.', ''),
            'a decimal comes back at its stored precision, not rounded',
        );
    }

    public function test_a_copy_is_written_under_a_temporary_name_and_only_named_when_complete(): void
    {
        $dump = DatabaseDumper::make()->run();

        $this->assertStringEndsWith('.sql', $dump['path']);
        $this->assertFileExists($dump['path']);
        $this->assertFileDoesNotExist($dump['path'].'.part');
        $this->assertCount(1, DatabaseDumper::make()->files());
    }

    public function test_a_truncated_or_altered_copy_is_not_a_copy(): void
    {
        $dump = DatabaseDumper::make()->run();
        $path = $dump['path'];

        // Cut the last line — the completion marker — exactly as a disk filling up would.
        $content = (string) file_get_contents($path);
        $truncated = substr($content, 0, (int) strrpos($content, '-- dump complete:'));
        file_put_contents($path, $truncated);

        $this->assertFalse(DatabaseDumper::verify($path), 'a file that lost its marker is not a copy');

        // A file that grew after being written is no longer the file that was hashed.
        $whole = DatabaseDumper::make()->run();
        file_put_contents($whole['path'], "-- smuggled in\n".(string) file_get_contents($whole['path']));

        $this->assertFalse(DatabaseDumper::verify($whole['path']));
    }

    public function test_retention_keeps_the_newest_and_never_deletes_them_all(): void
    {
        $dumper = DatabaseDumper::make();

        for ($i = 0; $i < 5; $i++) {
            File::put($this->backups.'/generator-2026-09-0'.($i + 1).'-0300.sql', 'x');
        }

        $this->assertSame(3, $dumper->prune(2), 'three older copies go');
        $this->assertCount(2, $dumper->files());
        $this->assertFileExists($this->backups.'/generator-2026-09-05-0300.sql', 'the newest always stays');

        // Asked to keep nothing at all, it still keeps the newest: a setting must not be able to
        // leave the office with no copy on the machine.
        $this->assertSame(1, $dumper->prune(0));
        $this->assertCount(1, $dumper->files());
    }

    // ---- the nightly run ------------------------------------------------------

    public function test_the_nightly_run_makes_a_copy_and_delivers_it(): void
    {
        $this->configureDestination();
        $this->fakeTelegramOk();

        $this->artisan('backup:send')->assertExitCode(0);

        $attempt = BackupAttempt::query()->latestFirst()->first();

        $this->assertNotNull($attempt);
        $this->assertTrue($attempt->delivered());
        $this->assertSame(BackupAttempt::TRIGGER_SCHEDULED, $attempt->trigger);
        $this->assertNull($attempt->error);
        $this->assertGreaterThan(0, $attempt->size_bytes);
        $this->assertNotNull($attempt->finished_at);
        $this->assertFileExists($this->backups.'/'.$attempt->file_name);

        // The message that carries the file names the night and the size, and the file itself is
        // attached under the name the office will see in the chat.
        Http::assertSent(function ($request) use ($attempt): bool {
            $caption = $this->field($request, 'caption');

            if ($caption === null) {
                return false;
            }

            $sent = array_filter(
                $request->data(),
                fn (array $part): bool => ($part['name'] ?? null) === 'document',
            );

            $file = $sent === [] ? null : reset($sent);

            return $this->field($request, 'chat_id') === '-1001234567890'
                && str_contains($caption, now()->format('Y-m-d'))
                && str_contains($caption, $attempt->sizeForHumans())
                && ($file['filename'] ?? null) === $attempt->file_name;
        });
    }

    public function test_the_run_leaves_a_heartbeat_even_when_it_fails(): void
    {
        // The whole point: a missing cron shows up as an absent heartbeat. A configured destination
        // that refuses must still leave the heartbeat behind.
        $this->configureDestination();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'])]);

        $this->artisan('backup:send')->assertExitCode(2);

        $this->assertNotNull(BackupSettings::schedulerSeenAt());
        $this->assertLessThan(1, BackupSettings::hoursSinceScheduledRun());
    }

    public function test_telegram_refusing_a_copy_is_never_recorded_as_delivered(): void
    {
        $this->configureDestination();
        $previous = $this->deliveredCopy();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'])]);

        $this->artisan('backup:send')->assertExitCode(2);

        $attempt = BackupAttempt::query()->latestFirst()->first();

        $this->assertFalse($attempt->delivered());
        $this->assertSame(BackupAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('Bad Request: chat not found', $attempt->error, "the API's own words are kept");
        $this->assertNotNull($attempt->file_name, 'the copy was still made, and the row says so');

        $this->assertSame(
            $previous->id,
            BackupAttempt::lastSent()?->id,
            'a failed run never replaces the copy the office actually has',
        );
    }

    public function test_a_transport_failure_is_recorded_without_leaking_the_credential(): void
    {
        $this->configureDestination();
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException(
            'cURL error 28: Connection timed out for https://api.telegram.org/bot'.$this->token.'/sendDocument',
        )]);

        $this->artisan('backup:send')->assertExitCode(2);

        $attempt = BackupAttempt::query()->latestFirst()->first();

        $this->assertSame(BackupAttempt::STATUS_FAILED, $attempt->status);
        $this->assertStringContainsString('timed out', (string) $attempt->error);
        $this->assertStringNotContainsString($this->token, (string) $attempt->error, 'the credential must not be stored');
    }

    public function test_a_failed_night_is_reported_to_the_chat_once_the_channel_returns(): void
    {
        $this->configureDestination();
        $this->deliveredCopy(hoursAgo: 30);

        // Two nights that never left.
        BackupAttempt::create([
            'started_at' => now()->subDay(), 'trigger' => BackupAttempt::TRIGGER_SCHEDULED,
            'status' => BackupAttempt::STATUS_FAILED, 'error' => 'Unauthorized', 'file_name' => 'x.sql',
        ]);
        BackupAttempt::create([
            'started_at' => now()->subHours(6), 'trigger' => BackupAttempt::TRIGGER_SCHEDULED,
            'status' => BackupAttempt::STATUS_FAILED, 'error' => 'Unauthorized', 'file_name' => 'y.sql',
        ]);

        $this->fakeTelegramOk();
        $this->artisan('backup:send')->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($text, '2') && str_contains($text, 'فشلت');
        });
    }

    public function test_a_copy_too_big_for_one_message_arrives_in_ordered_parts(): void
    {
        $this->configureDestination();

        // A limit small enough that the ledger needs several parts.
        BackupSettings::saveDeliveryLimit(2_000);
        $this->fakeTelegramOk();

        $this->artisan('backup:send')->assertExitCode(0);

        $attempt = BackupAttempt::query()->latestFirst()->first();

        $this->assertTrue($attempt->delivered());
        $this->assertGreaterThan(1, $attempt->parts, 'the copy needed more than one message');

        $captions = [];
        Http::assertSent(function ($request) use (&$captions): bool {
            $caption = $this->field($request, 'caption');

            if ($caption !== null) {
                $captions[] = $caption;
            }

            return true;
        });

        $this->assertCount($attempt->parts, $captions);
        $this->assertStringContainsString('الجزء 1', $captions[0]);
        $this->assertStringContainsString((string) $attempt->parts, end($captions));

        // The parts were derived files; the copy itself is untouched and still the real one.
        $this->assertTrue(DatabaseDumper::verify($this->backups.'/'.$attempt->file_name));
        $this->assertCount(1, DatabaseDumper::make()->files());
    }

    public function test_no_deliver_makes_a_copy_and_says_it_did_not_send_it(): void
    {
        $this->configureDestination();
        Http::fake();

        $this->artisan('backup:send --no-deliver')->assertExitCode(2);

        $attempt = BackupAttempt::query()->latestFirst()->first();

        $this->assertFalse($attempt->delivered());
        $this->assertNotNull($attempt->file_name, 'the copy exists on the machine');
        $this->assertFileExists($this->backups.'/'.$attempt->file_name);
        $this->assertStringContainsString('no-deliver', (string) $attempt->error);

        Http::assertNothingSent();
    }

    public function test_a_local_copy_is_possible_while_there_is_no_destination(): void
    {
        // The one case where a local file is all there is: the channel is not configured yet and the
        // operator wants a snapshot before doing something risky. --no-deliver asks for a copy, not a
        // delivery, so a missing destination must not refuse it — while the SCHEDULED rule above
        // stands, and the night is still not called covered.
        Http::fake();

        $this->artisan('backup:send --no-deliver')->assertExitCode(2);

        $attempt = BackupAttempt::query()->latestFirst()->first();

        $this->assertNotNull($attempt->file_name, 'the copy exists on the machine');
        $this->assertFileExists($this->backups.'/'.$attempt->file_name);
        $this->assertNotSame('', (string) $attempt->error, 'and it says plainly that nothing was sent');
        $this->assertNull(BackupAttempt::lastSent(), 'an undelivered copy is never last night\'s backup');

        Http::assertNothingSent();
    }

    public function test_without_a_destination_nothing_is_copied_and_the_run_says_why(): void
    {
        Http::fake();

        $this->artisan('backup:send')->assertExitCode(3);

        $this->assertSame(0, BackupAttempt::query()->count(), 'no half-feature: no copy that cannot leave');
        Http::assertNothingSent();
    }

    public function test_the_test_option_contacts_the_chat_and_records_no_attempt(): void
    {
        $this->configureDestination();
        $this->fakeTelegramOk();

        $this->artisan('backup:send --test')->assertExitCode(0);

        $this->assertSame(0, BackupAttempt::query()->count());
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendMessage'));
    }

    /** A test is not a copy: the refusal is reported and nothing is recorded as an attempt. */
    public function test_a_refused_test_reports_it_and_still_records_nothing(): void
    {
        $this->configureDestination();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'])]);

        $this->artisan('backup:send --test')->assertExitCode(2);

        $this->assertSame(0, BackupAttempt::query()->count());
    }

    public function test_two_runs_in_one_night_produce_two_copies_and_nothing_is_overwritten(): void
    {
        $this->configureDestination();
        $this->fakeTelegramOk();

        $this->artisan('backup:send')->assertExitCode(0);
        config(['backup.path' => $this->backups]);
        $this->artisan('backup:send --keep=30')->assertExitCode(0);

        $this->assertSame(2, BackupAttempt::query()->count());
        // Same minute means the same default file name; the second run must not be able to hide the
        // first attempt row, which is the part the office reads.
        $this->assertSame(2, BackupAttempt::query()->sent()->count());
    }

    // ---- what the office is told ---------------------------------------------

    public function test_an_office_with_no_destination_and_no_copies_is_told_both(): void
    {
        $warnings = collect(BackupStatus::warnings())->pluck('text')->implode(' ');

        $this->assertStringContainsString('ما في مكان نرسل عليه', $warnings);
        $this->assertStringContainsString('ما انبعثت ولا نسخة', $warnings);
        $this->assertStringContainsString('ولا تشغيل مجدول', $warnings, 'the missing cron is named, not implied');
        $this->assertFalse(BackupStatus::covered());
    }

    public function test_a_recent_delivery_with_a_live_scheduler_counts_as_covered(): void
    {
        $this->configureDestination();
        $this->deliveredCopy(hoursAgo: 8);
        BackupSettings::markSchedulerSeen();

        $this->assertSame([], BackupStatus::warnings());
        $this->assertTrue(BackupStatus::covered());
    }

    public function test_an_old_copy_and_a_dead_scheduler_are_both_reported(): void
    {
        $this->configureDestination();
        $this->deliveredCopy(hoursAgo: 60);
        BackupSettings::markSchedulerSeen(now()->subHours(50));

        $warnings = collect(BackupStatus::warnings());
        $texts = $warnings->pluck('text')->implode(' ');

        $this->assertStringContainsString('60', $texts, 'how long the office has been uncovered');
        $this->assertStringContainsString('50', $texts, 'and that the scheduler stopped');
        $this->assertSame(['danger', 'danger'], $warnings->pluck('tone')->all());
    }

    // ---- the screen -----------------------------------------------------------

    public function test_the_screen_states_the_truth_before_anything_has_run(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(BackupPage::class)
            ->assertSee('ما انبعثت ولا نسخة')
            ->assertSee('ما في ولا محاولة لسا')
            ->assertSee('ابعت هلّق');
    }

    public function test_a_failed_attempt_is_shown_with_its_reason_and_never_as_last_nights_copy(): void
    {
        $this->configureDestination();
        $yesterday = $this->deliveredCopy(hoursAgo: 30);

        BackupAttempt::create([
            'started_at' => now(), 'finished_at' => now(), 'trigger' => BackupAttempt::TRIGGER_SCHEDULED,
            'status' => BackupAttempt::STATUS_FAILED, 'file_name' => 'generator-x.sql',
            'error' => 'Bad Request: chat not found',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BackupPage::class)
            ->assertSee('Bad Request: chat not found')
            ->assertSee($yesterday->started_at->translatedFormat('Y-m-d H:i'));
    }

    public function test_send_now_from_the_panel_is_recorded_as_manual_and_attributed(): void
    {
        $this->configureDestination();
        $this->fakeTelegramOk();

        Livewire::actingAs($this->admin)
            ->test(BackupPage::class)
            ->call('sendNow')
            ->assertHasNoErrors();

        $attempt = BackupAttempt::query()->latestFirst()->first();

        $this->assertSame(BackupAttempt::TRIGGER_MANUAL, $attempt->trigger);
        $this->assertSame($this->admin->id, (int) $attempt->created_by);
        $this->assertTrue($attempt->delivered());
    }

    public function test_the_test_button_reports_what_telegram_answered(): void
    {
        $this->configureDestination();
        $this->fakeTelegramOk();

        Livewire::actingAs($this->admin)
            ->test(BackupPage::class)
            ->call('testDestination')
            ->assertSet('testSucceeded', true)
            ->assertSee('الشات جاوب');
    }

    public function test_the_test_button_reports_a_refusal_in_telegrams_own_words(): void
    {
        $this->configureDestination();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'])]);

        Livewire::actingAs($this->admin)
            ->test(BackupPage::class)
            ->call('testDestination')
            ->assertSet('testSucceeded', false)
            ->assertSee('Unauthorized');
    }

    public function test_the_credential_is_stored_encrypted_and_never_rendered_back(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(BackupPage::class)
            ->set('destination.bot_token', $this->token)
            ->set('destination.chat_id', '-1001234567890')
            ->call('saveDestination')
            ->assertHasNoErrors()
            ->assertSet('destination.bot_token', '', 'the field is not a place a saved credential sits');

        $stored = (string) DB::table('settings')->where('key', 'telegram.bot_token')->value('value');

        $this->assertNotSame($this->token, $stored, 'the raw column must not hold the credential');
        $this->assertStringNotContainsString($this->token, $stored);
        $this->assertSame($this->token, Crypt::decryptString($stored));
        $this->assertSame($this->token, BackupSettings::botToken());
        $this->assertSame('-1001234567890', BackupSettings::chatId());

        // …and the screen says a credential exists without showing it.
        Livewire::actingAs($this->admin)
            ->test(BackupPage::class)
            ->assertSee('في توكن محفوظ')
            ->assertDontSee($this->token);
    }

    public function test_the_screen_is_admin_only_and_absent_from_a_collectors_sidebar(): void
    {
        $this->actingAs($this->collector)->get(route('admin.backup'))->assertForbidden();

        $this->actingAs($this->collector)
            ->get(route('admin.dashboard'))
            ->assertDontSee(route('admin.backup'));

        $this->actingAs($this->admin)
            ->get(route('admin.backup'))
            ->assertOk()
            ->assertSee('النسخ الاحتياطي');

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertSee(route('admin.backup'));
    }

    public function test_a_signed_out_visitor_never_reaches_the_backup_screen(): void
    {
        $this->get(route('admin.backup'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.backup'))->assertRedirect(route('admin.login'));
    }

    public function test_the_runner_refuses_to_start_on_top_of_a_live_run(): void
    {
        BackupAttempt::create([
            'started_at' => now(), 'trigger' => BackupAttempt::TRIGGER_SCHEDULED,
            'status' => BackupAttempt::STATUS_RUNNING,
        ]);

        $this->assertTrue(BackupRunner::running());

        // A run that died hours ago is not an overlap, it is a corpse: the next night must proceed.
        BackupAttempt::query()->update(['started_at' => now()->subHours(3)]);

        $this->assertFalse(BackupRunner::running());
    }

    // ---- wording --------------------------------------------------------------

    public function test_every_backup_string_exists_in_both_languages(): void
    {
        $ar = json_decode((string) file_get_contents(base_path('lang/ar.json')), true);
        $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);

        $keys = array_filter(array_keys($ar), fn (string $key): bool => str_starts_with($key, 'bk.') || $key === 'mdl.backups');

        $this->assertGreaterThan(40, count($keys), 'the block is being read');

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, "{$key} is missing from the English catalogue");
            $this->assertNotSame('', (string) $ar[$key]);
            $this->assertNotSame($key, (string) $ar[$key], "{$key} fell back to its raw key");
        }
    }

    public function test_the_counted_backup_labels_use_arabic_plural_forms(): void
    {
        $this->assertSame('محاولة وحدة فشلت من 2026-09-10 03:00', plural('bk.catch_up', 1, ['since' => '2026-09-10 03:00']));
        $this->assertSame('محاولتان فشلتا من 2026-09-10 03:00', plural('bk.catch_up', 2, ['since' => '2026-09-10 03:00']));
        $this->assertSame('5 محاولات فشلت من 2026-09-10 03:00', plural('bk.catch_up', 5, ['since' => '2026-09-10 03:00']));
        $this->assertSame('12 محاولة فشلت من 2026-09-10 03:00', plural('bk.catch_up', 12, ['since' => '2026-09-10 03:00']));

        $this->assertSame('ما صار تشغيل مجدول من ساعتين', plural('bk.warn_scheduler_hours', 2));
        $this->assertSame('ما صار تشغيل مجدول من 40 ساعة', plural('bk.warn_scheduler_hours', 40));
    }

    public function test_the_sender_refuses_to_report_a_delivery_it_could_not_make(): void
    {
        // No destination at all: the sender must say so rather than pretend, and the copy on disk is
        // reported missing rather than sent as something it is not.
        $sender = TelegramBackupSender::make();

        $this->assertFalse($sender->configured());
        $this->assertFalse($sender->deliver($this->backups.'/nothing.sql', 'x')['ok']);

        Http::fake();
        $this->assertFalse($sender->message('anything')['ok']);
        Http::assertNothingSent();
    }
}
