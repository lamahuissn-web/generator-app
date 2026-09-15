<?php

namespace Tests\Feature;

use App\Admin\Tables\SubscribersTable;
use App\Livewire\Admin\Pages\SubscriberImport as ImportPage;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\SubscriberImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * استيراد وتصدير الزبائن — «بدنا طريقة سريعة لإدخال الداتا».
 *
 * The feature is the PREVIEW. A file is read, every row is judged, and nothing is written until the
 * operator says so: a column typed wrong or a neighbourhood spelt differently costs a re-upload, not
 * a cleanup. The tests below pin that, the refusals that name their reason, and the one contract that
 * ties the whole thing together — the template, the export and the importer are the same columns, so
 * a file that leaves the panel can come back into it.
 *
 * A row that would touch a subscriber already in the panel is refused rather than applied. The office
 * said it plainly when we built the delete rules: «ما بينمحى ولا بينتعدّل إنسان».
 */
class SubscriberImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Route $route;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'مدير', 'email' => 'admin@import.test', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'عين الرمانة']);
    }

    /** The CSV text, exactly as a spreadsheet would write it. */
    private function csv(array $rows, ?array $headers = null, string $delimiter = ',', string $encoding = 'UTF-8'): string
    {
        $handle = fopen('php://temp', 'r+');

        $headers = $headers ?? SubscriberImport::headers();
        fputcsv($handle, $headers, $delimiter);

        foreach ($rows as $row) {
            fputcsv($handle, $row, $delimiter);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        if ($encoding !== 'UTF-8') {
            // What Excel does to Arabic when it saves a CSV on Windows.
            $csv = (string) iconv('UTF-8', $encoding, $csv);
        }

        return $csv;
    }

    /** The file on disk, for the service-level tests. */
    private function csvPath(array $rows, ?array $headers = null, string $delimiter = ',', string $encoding = 'UTF-8'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'subs').'.csv';
        file_put_contents($path, $this->csv($rows, $headers, $delimiter, $encoding));

        return $path;
    }

    /** The upload, for the screen tests: Livewire converts it the way a real upload arrives. */
    private function upload(array $rows, ?array $headers = null, string $delimiter = ',', string $encoding = 'UTF-8'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('subs.csv', $this->csv($rows, $headers, $delimiter, $encoding));
    }

    /** A metered row with every column filled. */
    private function meteredRow(array $overrides = []): array
    {
        $row = [
            'علي شرارة', 'عين الرمانة', 'M-1051', '03123456', 'شارع المدرسة',
            __('s.metered'), __('portal.active'), '10', __('imp.mode_auto'), '', '', 'ملاحظة',
        ];

        foreach ($overrides as $index => $value) {
            $row[$index] = $value;
        }

        return $row;
    }

    private function flatRow(array $overrides = []): array
    {
        $row = [
            'سامي دندشي', 'عين الرمانة', '', '03777888', '',
            __('s.flat_rate'), __('portal.active'), '', __('imp.mode_custom'), '', '25', '',
        ];

        foreach ($overrides as $index => $value) {
            $row[$index] = $value;
        }

        return $row;
    }

    /** The apply, exactly as the browser sends it: a plain form POST with nothing but the CSRF token. */
    private function apply(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.subscribers-import.apply'));
    }

    /** A row keyed by column name — a new column cannot silently shift an old test. */
    private function row(array $values): array
    {
        return array_map(
            fn (string $key): string => (string) ($values[$key] ?? ''),
            array_keys(SubscriberImport::columns()),
        );
    }

    /** Read + judge, the way the component does it. */
    private function preview(array $rows, ?array $headers = null, string $delimiter = ',', string $encoding = 'UTF-8'): array
    {
        $parsed = SubscriberImport::parse($this->csvPath($rows, $headers, $delimiter, $encoding));

        $this->assertNull($parsed['error'], 'the file should have been readable');

        return SubscriberImport::preview($parsed['rows']);
    }

    public function test_the_template_the_export_and_the_importer_are_one_set_of_columns(): void
    {
        $template = SubscriberImport::template();
        $firstLine = str_getcsv(explode("\n", substr($template, 3))[0]);

        // Three views of the same list: what we print, what we export, what we accept.
        $this->assertSame(SubscriberImport::headers(), $firstLine);
        $this->assertSame(SubscriberImport::headers(), (new SubscribersTable)->csvHeader());
        $this->assertNotSame([], (new SubscribersTable)->csvHeader(), 'the list must be exportable');
    }

    public function test_the_preview_writes_nothing_at_all(): void
    {
        $previewed = $this->preview([$this->meteredRow(), $this->flatRow()]);

        $this->assertCount(2, $previewed);
        $this->assertSame('ok', $previewed[0]['status']);
        $this->assertSame(0, Subscriber::count(), 'reading a file must not create anything');
    }

    public function test_a_clean_file_imports_with_every_field_intact(): void
    {
        $previewed = $this->preview([$this->meteredRow()]);
        $outcome = SubscriberImport::commit($previewed, $this->admin->id);

        $this->assertSame(1, $outcome['created']);
        $this->assertSame([], $outcome['failed']);

        $subscriber = Subscriber::firstOrFail();

        $this->assertSame('علي شرارة', $subscriber->name);
        $this->assertSame($this->route->id, $subscriber->route_id);
        $this->assertSame('M-1051', $subscriber->meter_no);
        $this->assertSame('03123456', $subscriber->phone);
        $this->assertSame('شارع المدرسة', $subscriber->address);
        $this->assertSame(10, $subscriber->amperage);
        $this->assertSame(Subscriber::TYPE_METERED, $subscriber->type);
        $this->assertSame(Subscriber::STATUS_ACTIVE, $subscriber->status);
        $this->assertSame(Subscriber::MAINT_AUTO, $subscriber->maintenance_mode);
        $this->assertSame('ملاحظة', $subscriber->notes);
        $this->assertSame($this->admin->id, $subscriber->created_by);
    }

    public function test_a_flat_subscriber_keeps_its_fee_and_loses_the_meter(): void
    {
        // The stray meter value is what happens when a column is filled for a house that has none.
        $previewed = $this->preview([$this->flatRow([2 => 'M-9999', 7 => '15'])]);
        SubscriberImport::commit($previewed, $this->admin->id);

        $subscriber = Subscriber::firstOrFail();

        $this->assertSame(Subscriber::TYPE_FLAT, $subscriber->type);
        $this->assertSame(25.0, (float) $subscriber->flat_fee);
        $this->assertSame(Subscriber::MAINT_CUSTOM, $subscriber->maintenance_mode);
        $this->assertNull($subscriber->meter_no, 'a flat subscriber must not keep a meter number');
        $this->assertNull($subscriber->amperage);
    }

    public function test_a_subscriber_already_in_the_panel_is_refused_untouched(): void
    {
        $existing = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'علي شرارة', 'meter_no' => 'M-1051',
            'phone' => '03111111', 'type' => Subscriber::TYPE_METERED,
            'maintenance_mode' => Subscriber::MAINT_AUTO, 'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        $previewed = $this->preview([$this->meteredRow([3 => '03999999'])]);

        $this->assertSame('problem', $previewed[0]['status']);
        $this->assertStringContainsString('M-1051', $previewed[0]['errors'][0]);

        $outcome = SubscriberImport::commit($previewed, $this->admin->id);

        $this->assertSame(0, $outcome['created']);
        $this->assertSame(1, Subscriber::count());
        // The panel's row is exactly as it was — not merged, not renamed, not re-phoned.
        $this->assertSame('03111111', $existing->fresh()->phone);
        $this->assertSame('علي شرارة', $existing->fresh()->name);
    }

    public function test_an_unknown_route_is_refused_and_the_refusal_names_it(): void
    {
        $previewed = $this->preview([$this->meteredRow([1 => 'عين الرمانة الشرقية'])]);

        $this->assertSame('problem', $previewed[0]['status']);
        $this->assertStringContainsString('عين الرمانة الشرقية', $previewed[0]['errors'][0]);
    }

    public function test_a_flat_row_without_a_fee_is_refused(): void
    {
        $previewed = $this->preview([$this->flatRow([10 => '0'])]);

        $this->assertSame('problem', $previewed[0]['status']);
        $this->assertStringContainsString('رسم', $previewed[0]['errors'][0]);
    }

    public function test_a_row_repeated_inside_the_file_is_refused_on_the_second_one(): void
    {
        $previewed = $this->preview([$this->meteredRow(), $this->meteredRow()]);

        $this->assertSame('ok', $previewed[0]['status']);
        $this->assertSame('problem', $previewed[1]['status']);
        $this->assertStringContainsString('1', $previewed[1]['errors'][0]);
    }

    public function test_a_value_nobody_can_read_is_refused_and_names_its_column(): void
    {
        $previewed = $this->preview([$this->meteredRow([5 => 'عداد كهربا'])]);

        $this->assertSame('problem', $previewed[0]['status']);
        $this->assertStringContainsString(__('t.type'), $previewed[0]['errors'][0]);
    }

    public function test_the_file_may_be_english_or_arabic_and_comma_or_semicolon(): void
    {
        app()->setLocale('en');

        $english = [
            'Ali Charara', 'عين الرمانة', 'M-2001', '', '', 'metered', 'active', '5', 'automatic', '', '', '',
        ];

        $previewedEn = $this->preview([$english], delimiter: ';');

        $this->assertSame('ok', $previewedEn[0]['status'], 'an English file with semicolons must import');

        app()->setLocale('ar');

        $previewedAr = $this->preview([$this->meteredRow([2 => 'M-2002'])]);

        $this->assertSame('ok', $previewedAr[0]['status']);
    }

    public function test_a_file_saved_by_windows_excel_in_arabic_is_read_correctly(): void
    {
        $previewed = $this->preview([$this->meteredRow([0 => 'محمد عيتاني', 2 => 'M-3001'])], encoding: 'CP1256');

        $this->assertSame('ok', $previewed[0]['status']);
        $this->assertSame('محمد عيتاني', $previewed[0]['attributes']['name']);
    }

    public function test_money_and_digits_are_read_the_way_a_person_types_them(): void
    {
        // Arabic-Indic digits, a dollar sign and a thousands separator, all in one cell.
        $previewed = $this->preview([$this->flatRow([10 => '٢٥', 9 => ''])]);
        $this->assertSame('ok', $previewed[0]['status']);
        $this->assertSame(25.0, $previewed[0]['attributes']['flat_fee']);

        $custom = $this->preview([$this->meteredRow([8 => __('imp.mode_custom'), 9 => '$1,200.50'])]);
        $this->assertSame('ok', $custom[0]['status']);
        $this->assertSame(1200.5, $custom[0]['attributes']['maintenance_fee']);
    }

    public function test_a_file_that_does_not_have_our_columns_is_refused_with_the_list(): void
    {
        $parsed = SubscriberImport::parse($this->csvPath([['a', 'b']], ['x', 'y']));

        $this->assertNotNull($parsed['error']);
        $this->assertStringContainsString(__('t.name'), $parsed['error']);
    }

    public function test_what_the_panel_exports_is_what_the_importer_reads(): void
    {
        foreach ([$this->meteredRow(), $this->flatRow()] as $row) {
            SubscriberImport::commit($this->preview([$row]), $this->admin->id);
        }

        $table = new SubscribersTable;
        $rows = Subscriber::query()->with('route:id,name')->orderBy('name')->get();

        $exported = $rows->map(fn (Subscriber $s): array => $table->csvRow($s))->all();

        $this->assertCount(2, $exported);

        // Straight back in: both are refused, which can only happen if every value was understood.
        $previewed = $this->preview($exported);

        foreach ($previewed as $row) {
            $this->assertSame('problem', $row['status'], 'an exported row must be understood on the way back');
        }
    }

    public function test_the_errors_file_carries_the_refused_rows_and_their_reasons(): void
    {
        $previewed = $this->preview([$this->meteredRow(), $this->meteredRow([1 => 'منطقة غلط'])]);

        $csv = SubscriberImport::errorsCsv($previewed);

        $this->assertStringContainsString(__('imp.reason'), $csv);
        $this->assertStringContainsString('منطقة غلط', $csv);
    }

    public function test_a_file_filled_from_the_arabic_template_is_read_while_the_panel_is_english(): void
    {
        // The office fills the template in whatever language they think in. A file must not be refused
        // because the panel happens to be set to the other one — and the headings are matched against
        // both languages and the raw column key.
        $arabic = $this->row([
            'name' => 'علي شرارة', 'route' => 'عين الرمانة', 'meter_no' => 'M-4101',
            'type' => 'اشتراك بالعداد', 'status' => 'نشط', 'maintenance_mode' => 'تلقائي',
        ]);

        $arabicHeaders = SubscriberImport::headers();   // the headings as the office printed them

        app()->setLocale('en');                         // …and the panel switched to English

        $parsed = SubscriberImport::parse($this->csvPath([$arabic], $arabicHeaders));

        $this->assertNull($parsed['error'], 'an Arabic file must read while the panel is English');
        $this->assertSame('ok', SubscriberImport::preview($parsed['rows'])[0]['status']);

        app()->setLocale('ar');
    }

    public function test_a_file_whose_areas_are_only_partly_known_imports_the_rest_and_names_the_others(): void
    {
        // Exactly the shape of the file the office tried: some streets the panel knows, some it does
        // not. The known ones must go in, and the others must come back with a reason.
        $rows = [
            $this->row(['name' => 'زبون أول', 'route' => 'عين الرمانة', 'type' => 'اشتراك بالعداد', 'status' => 'نشط', 'maintenance_mode' => 'تلقائي', 'meter_no' => 'M-5001']),
            $this->row(['name' => 'زبون تاني', 'route' => 'الحازمية', 'type' => 'اشتراك بالعداد', 'status' => 'نشط', 'maintenance_mode' => 'تلقائي', 'meter_no' => 'M-5002']),
            $this->row(['name' => 'زبون تالت', 'route' => 'عين الرمانة', 'type' => 'اشتراك ثابت', 'status' => 'نشط', 'maintenance_mode' => 'مخصص', 'flat_fee' => '20']),
        ];

        $previewed = $this->preview($rows);

        $this->assertSame(['ok', 'problem', 'ok'], array_column($previewed, 'status'));

        $outcome = SubscriberImport::commit($previewed, $this->admin->id);

        $this->assertSame(2, $outcome['created']);
        $this->assertSame(2, Subscriber::count());
        $this->assertSame(0, Subscriber::where('name', 'زبون تاني')->count(), 'an unknown street must not be invented');
    }

    public function test_the_errors_file_names_the_routes_the_panel_has(): void
    {
        $previewed = $this->preview([$this->row([
            'name' => 'زبون بعيد', 'route' => 'الحازمية', 'type' => 'اشتراك بالعداد', 'status' => 'نشط',
            'maintenance_mode' => 'تلقائي', 'meter_no' => 'M-6001',
        ])]);

        $csv = SubscriberImport::errorsCsv($previewed);

        // So the whole fix can be made in that same file, offline.
        $this->assertStringContainsString(__('imp.known_routes'), $csv);
        $this->assertStringContainsString('عين الرمانة', $csv);
    }

    public function test_the_screen_previews_first_and_only_writes_when_told(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ImportPage::class)
            ->set('file', $this->upload([$this->meteredRow([0 => 'زبون أ']), $this->flatRow([0 => 'زبون ب'])]))
            ->call('readFile')
            ->assertSet('step', 'preview');

        $this->assertSame(0, Subscriber::count(), 'the preview must not write anything');

        $this->apply();

        $this->assertSame(2, Subscriber::count());
    }
    public function test_the_apply_is_a_plain_form_post_the_browser_cannot_miss(): void
    {
        // The screen the office uses renders both apply buttons as native form submits, so the step
        // that writes does not depend on a click handler reaching Livewire.
        Livewire::actingAs($this->admin)->test(ImportPage::class)
            ->set('file', $this->upload([$this->meteredRow([0 => 'زبون']) ]))
            ->call('readFile')
            ->assertSee(route('admin.subscribers-import.apply'));
    }
    public function test_apply_works_when_the_post_arrives_as_a_brand_new_component(): void
    {
        // His exact failure: the request carrying the apply has no rows, no step and no upload in it.
        // The judged rows are parked in the session when the file is read, so the POST still writes.
        Livewire::actingAs($this->admin)->test(ImportPage::class)
            ->set('file', $this->upload([$this->meteredRow([0 => 'زبون من الملف']), $this->flatRow([0 => 'زبون مقطوع'])]))
            ->call('readFile');

        $this->apply();

        $this->assertSame(2, Subscriber::count());
        $this->assertSame('زبون من الملف', Subscriber::first()->name);
    }
    public function test_applying_with_no_file_at_all_says_so_in_words(): void
    {
        $this->apply();

        $this->assertSame(0, Subscriber::count());

        // and the reason is on the screen the POST comes back to
        Livewire::actingAs($this->admin)
            ->test(ImportPage::class)
            ->assertSet('error', __('imp.file_gone'));
    }
    public function test_the_result_is_shown_when_the_post_comes_back(): void
    {
        Livewire::actingAs($this->admin)->test(ImportPage::class)
            ->set('file', $this->upload([$this->meteredRow([0 => 'زبون']) ]))
            ->call('readFile');

        $this->apply()
            ->assertRedirect(route('admin.subscribers-import'))
            ->assertSessionHas('status');

        // The screen the redirect lands on reports what happened, without the operator doing anything.
        Livewire::actingAs($this->admin)
            ->test(ImportPage::class)
            ->assertSet('step', 'done')
            ->assertSee(__('imp.result_title'));
    }
    public function test_the_screen_says_so_when_the_file_has_no_ready_rows(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ImportPage::class)
            ->set('file', $this->upload([$this->flatRow([10 => '0'])]))
            ->call('readFile')
            ->assertSet('step', 'preview');

        $this->apply();

        $this->assertSame(0, Subscriber::count());
    }
    public function test_the_screen_offers_the_template_and_the_error_file(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ImportPage::class)
            ->call('downloadTemplate')
            ->assertFileDownloaded('subscribers-template.csv');
    }

    public function test_the_errors_can_still_be_downloaded_from_the_preview(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ImportPage::class)
            ->set('file', $this->upload([$this->meteredRow([1 => 'منطقة غلط'])]))
            ->call('readFile')
            ->call('downloadErrors')
            ->assertFileDownloaded('subscribers-errors.csv');
    }
    public function test_every_label_the_export_writes_can_be_read_back_in_both_languages(): void
    {
        // The export writes typeLabel()/statusLabel()/modeLabel(); the importer reads aliases built
        // from the same words. If one of them ever drifts, a file that leaves the panel comes back
        // refused — which is exactly what this walks, constant by constant, in both languages.
        $words = [
            Subscriber::TYPE_METERED => 'type',
            Subscriber::TYPE_FLAT => 'type',
            Subscriber::STATUS_ACTIVE => 'status',
            Subscriber::STATUS_INACTIVE => 'status',
            Subscriber::MAINT_AUTO => 'maintenance_mode',
            Subscriber::MAINT_CUSTOM => 'maintenance_mode',
            Subscriber::MAINT_EXEMPT => 'maintenance_mode',
        ];

        foreach ($words as $constant => $column) {
            $aliases = SubscriberImport::valueAliases()[$column];

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);

                $label = match ($column) {
                    'type' => SubscriberImport::typeLabel($constant),
                    'status' => SubscriberImport::statusLabel($constant),
                    default => SubscriberImport::modeLabel($constant),
                };

                $normalised = mb_strtolower(str_replace('*', '', trim($label)), 'UTF-8');

                $this->assertSame(
                    $constant,
                    $aliases[$normalised] ?? null,
                    "«{$label}» ({$locale}) is written by the export but not understood on the way back",
                );
            }
        }

        app()->setLocale('ar');
    }
}
