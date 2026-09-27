<?php

namespace Tests\Feature;

use App\Admin\Actions\CorrectRefuelling;
use App\Admin\Actions\CorrectService;
use App\Admin\Actions\RecordRefuelling;
use App\Livewire\Admin\ActionForm;
use App\Models\Equipment;
use App\Models\EquipmentService;
use App\Models\Refuelling;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the browser REFUSES, which no PHP assertion ever sees.
 *
 * `<input type="number">` defaults to `step="1"`, and when `min` is present the step base IS that min — so
 * `min="0.01"` makes the only valid values 0.01, 1.01, 2.01 … 199.01, 200.01: **200 litres is invalid**, the
 * browser blocks the submit before any request leaves the page, and the operator reads an English tooltip
 * ("The two nearest valid values are 199.01 and 200.01") in an Arabic-only app. The suite was green at 720
 * tests while the office could not type the one number it types every day.
 *
 * The only layer that can see this is the rendered HTML, so that is what these tests read: the field
 * component's own output, and the modal the daily button actually opens.
 */
class NumberFieldEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Equipment $device;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        // The field component asks the view's error bag for a reserved message line; a bare view render in
        // a test has no middleware to share one.
        $this->app['view']->share('errors', new ViewErrorBag);

        $this->admin = User::create([
            'name' => 'مدير', 'email' => 'admin@n.com', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);

        $this->device = Equipment::create([
            'name' => 'المولّد', 'kind' => Equipment::KIND_GENERATOR, 'status' => Equipment::STATUS_WORKING,
            'engine_hours_opening' => 0, 'created_by' => $this->admin->id,
        ]);
    }

    /** The <input> a field schema produces, as one line of HTML. */
    private function inputFor(array $field): string
    {
        $html = view('components.admin.field', ['field' => $field, 'model' => 'state', 'values' => []])->render();

        if (! preg_match('/<input[^>]*id="f-'.preg_quote($field['key'], '/').'"[^>]*>/u', $html, $m)) {
            $this->fail("no input rendered for {$field['key']}: ".trim(preg_replace('/\s+/', ' ', $html)));
        }

        return preg_replace('/\s+/', ' ', $m[0]);
    }

    public function test_a_number_field_is_never_pinned_to_an_implicit_step_of_one(): void
    {
        // min 0.01 is what the litres box declares: with the browser's implicit step, whole litres are
        // refused. `step="any"` is what makes 200 — and 199.5 — typeable.
        $tag = $this->inputFor(['key' => 'litres', 'label' => 'اللترات', 'type' => 'number', 'min' => 0.01]);

        $this->assertStringContainsString('step="any"', $tag);
        $this->assertStringContainsString('min="0.01"', $tag, 'the floor still applies');
        $this->assertStringNotContainsString('step="1"', $tag);

        // A phone keypad without a point cannot enter 199.5 at all.
        $this->assertStringContainsString('inputmode="decimal"', $tag);
    }

    public function test_a_whole_number_field_also_accepts_a_decimal(): void
    {
        // engine hours and produced kWh are typed as 4,375.5 and 739.5 in the field.
        $tag = $this->inputFor(['key' => 'engine_hours', 'label' => 'ساعات', 'type' => 'number', 'min' => 0]);

        $this->assertStringContainsString('step="any"', $tag);
        $this->assertStringContainsString('min="0"', $tag);
    }

    public function test_a_definition_may_still_declare_its_own_step(): void
    {
        $tag = $this->inputFor(['key' => 'months', 'label' => 'أشهر', 'type' => 'number', 'min' => 1, 'step' => 1]);

        $this->assertStringContainsString('step="1"', $tag);
        $this->assertStringNotContainsString('step="any"', $tag);
    }

    public function test_the_daily_refuelling_modal_hands_the_browser_a_usable_litres_box(): void
    {
        $html = $this->modalHtml(RecordRefuelling::class, $this->device->id);

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="a-litres"[^>]*>/u',
            $html,
            'the litres box is on the screen'
        );

        preg_match('/<input[^>]*id="a-litres"[^>]*>/u', $html, $m);
        $tag = preg_replace('/\s+/', ' ', $m[0]);

        $this->assertStringContainsString('step="any"', $tag);
        $this->assertStringContainsString('min="0.01"', $tag);
    }

    public function test_the_correction_modals_hand_the_browser_the_same_box(): void
    {
        $refuelling = Refuelling::create([
            'equipment_id' => $this->device->id, 'date' => now()->toDateString(), 'litres' => 200,
            'price_per_litre' => 1.25, 'kwh_source' => Refuelling::SOURCE_ESTIMATED, 'created_by' => $this->admin->id,
        ]);

        $service = EquipmentService::create([
            'equipment_id' => $this->device->id, 'date' => now()->toDateString(),
            'kind' => EquipmentService::KIND_OIL, 'cost' => 40, 'created_by' => $this->admin->id,
        ]);

        $refuellingTag = $this->tagOf($this->modalHtml(CorrectRefuelling::class, $refuelling->id), 'a-litres');
        $this->assertStringContainsString('step="any"', $refuellingTag);
        $this->assertStringContainsString('min="0.01"', $refuellingTag);

        // And the correction form opens on the row's own figure — 200 must be a value it can hand back.
        // (Livewire carries the value in its snapshot, not as a `value` attribute, so read the state.)
        Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => CorrectRefuelling::class, 'subjectId' => $refuelling->id])
            ->call('openModal')
            ->assertSet('state.litres', 200);

        $serviceTag = $this->tagOf($this->modalHtml(CorrectService::class, $service->id), 'a-engine_hours');
        $this->assertStringContainsString('step="any"', $serviceTag);
    }

    private function modalHtml(string $action, int $subjectId): string
    {
        return Livewire::actingAs($this->admin)
            ->test(ActionForm::class, ['action' => $action, 'subjectId' => $subjectId])
            ->call('openModal')
            ->html();
    }

    private function tagOf(string $html, string $id): string
    {
        if (! preg_match('/<input[^>]*id="'.preg_quote($id, '/').'"[^>]*>/u', $html, $m)) {
            $this->fail("no input {$id} in the modal");
        }

        return $m[0];
    }
}
