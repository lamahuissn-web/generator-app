<?php

namespace App\Livewire\Admin\Pages;

use App\Models\Setting;
use App\Services\BillingService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The settings the rest of the app reads at runtime.
 *
 * Every key here is read through Setting::get() somewhere in the billing path, so saving is
 * a real change to what the next invoice costs — the helper text under each field says where
 * the value is used.
 *
 * State is a flat property rather than a FormDefinition: a settings screen is one form, not
 * a table of records, and there is no list to be consistent with.
 */
class Settings extends Component
{
    public static function subheading(): string
    {
        return __('set.sub');
    }

    /** @var array<string, mixed> */
    public array $state = [];

    public function mount(): void
    {
        foreach (array_keys($this->keys()) as $key) {
            $this->state[$key] = (string) Setting::get($key, self::fallback()[$key] ?? '');
        }
    }

    /** The defaults a fresh install behaves as if the row is missing. */
    private static function fallback(): array
    {
        return [
            'kwh_price' => '0.25',
            'default_maintenance_fee' => '0',
            'lbp_rate' => '0',
            'bill_rounding' => BillingService::ROUND_NONE,
            'discount_categories' => 'مسجد,قريب,حالة إنسانية,أخرى',
            // مركز العمليات: the answers the centre needs once, so the owner is never asked for them monthly.
            'kwh_per_litre' => (string) \App\Reports\OperationsReport::DEFAULT_KWH_PER_LITRE,
            'diesel_price_default' => '',
            'flat_draw_factor' => '40',
            'loss_band_normal' => '',
            'maintenance_alert_hours' => '250,500,2000',
        ];
    }

    /** Validation for the numeric keys: a negative kWh price would silently zero every bill. */
    public function rules(): array
    {
        return [
            'state.kwh_price' => ['required', 'numeric', 'min:0'],
            'state.default_maintenance_fee' => ['required', 'numeric', 'min:0'],
            // 0 is allowed and means "show nothing in lira": an operator who bills in dollars
            // only must not be forced into a rate he does not use.
            'state.lbp_rate' => ['required', 'numeric', 'min:0'],
            // Only the two rules the billing service knows how to apply: a stored third option
            // would be a setting the panel silently ignores.
            'state.bill_rounding' => ['required', Rule::in([BillingService::ROUND_NONE, BillingService::ROUND_CEIL_DOLLAR])],
            'state.discount_categories' => ['required', 'string', 'max:200'],
            // A rate of zero would make every litre produce nothing and every loss infinite.
            'state.kwh_per_litre' => ['required', 'numeric', 'gt:0'],
            'state.diesel_price_default' => ['nullable', 'numeric', 'min:0'],
            'state.flat_draw_factor' => ['required', 'numeric', 'min:0', 'max:100'],
            // Empty is a valid answer and means "no verdict": the centre then shows the loss without judging it.
            'state.loss_band_normal' => ['nullable', 'string', 'max:40'],
            'state.maintenance_alert_hours' => ['required', 'string', 'max:60'],
            'state.company_name' => ['nullable', 'string', 'max:120'],
            'state.company_phone' => ['nullable', 'string', 'max:40'],
            'state.company_address' => ['nullable', 'string', 'max:180'],
            'state.bill_footer' => ['nullable', 'string', 'max:180'],
        ];
    }

    /** @return array<string, mixed> key => field schema */
    public function keys(): array
    {
        return [
            'kwh_price' => ['key' => 'kwh_price', 'label' => __('set.kwh_label'), 'type' => 'money', 'helper' => __('set.kwh_helper'), 'rules' => ['required']],
            'default_maintenance_fee' => ['key' => 'default_maintenance_fee', 'label' => __('set.maint_label'), 'type' => 'money', 'helper' => __('set.maint_helper'), 'rules' => ['required']],
            'bill_rounding' => [
                'key' => 'bill_rounding',
                'label' => __('set.rounding_label'),
                'type' => 'select',
                'options' => [
                    BillingService::ROUND_NONE => __('set.round_none'),
                    BillingService::ROUND_CEIL_DOLLAR => __('set.round_ceil_dollar'),
                ],
                'helper' => __('set.rounding_helper'),
                'rules' => ['required'],
            ],
            'lbp_rate' => ['key' => 'lbp_rate', 'label' => __('set.lbp_label'), 'type' => 'number', 'helper' => __('set.lbp_helper'), 'rules' => ['required'], 'min' => 0],
            'discount_categories' => ['key' => 'discount_categories', 'label' => __('set.discount_categories_label'), 'helper' => __('set.discount_categories_helper'), 'placeholder' => 'مسجد,قريب,حالة إنسانية,أخرى', 'span' => 'full', 'rules' => ['required']],
            // ---- مركز العمليات (the machines) ----------------------------------------------------
            'kwh_per_litre' => ['key' => 'kwh_per_litre', 'label' => __('set.kwh_per_litre_label'), 'type' => 'number', 'helper' => __('set.kwh_per_litre_helper'), 'rules' => ['required'], 'min' => 0],
            'flat_draw_factor' => ['key' => 'flat_draw_factor', 'label' => __('set.flat_factor_label'), 'type' => 'number', 'helper' => __('set.flat_factor_helper'), 'rules' => ['required'], 'min' => 0],
            'diesel_price_default' => ['key' => 'diesel_price_default', 'label' => __('set.diesel_price_label'), 'type' => 'money', 'helper' => __('set.diesel_price_helper'), 'rules' => ['nullable'], 'min' => 0],
            'loss_band_normal' => ['key' => 'loss_band_normal', 'label' => __('set.loss_band_label'), 'helper' => __('set.loss_band_helper'), 'placeholder' => '5-15', 'rules' => ['nullable']],
            'maintenance_alert_hours' => ['key' => 'maintenance_alert_hours', 'label' => __('set.maint_hours_label'), 'helper' => __('set.maint_hours_helper'), 'placeholder' => '250,500,2000', 'span' => 'full', 'rules' => ['required']],
            'company_name' => ['key' => 'company_name', 'label' => __('set.company_name'), 'placeholder' => __('prt.company_default'), 'rules' => ['nullable']],
            'company_phone' => ['key' => 'company_phone', 'label' => __('set.company_phone'), 'type' => 'tel', 'rules' => ['nullable']],
            'company_address' => ['key' => 'company_address', 'label' => __('set.company_address'), 'rules' => ['nullable']],
            'bill_footer' => ['key' => 'bill_footer', 'label' => __('set.company_footer'), 'span' => 'full', 'helper' => __('set.company_footer_helper'), 'rules' => ['nullable']],
        ];
    }

    public function save()
    {
        $this->validate();

        foreach (array_keys($this->keys()) as $key) {
            Setting::set($key, (string) ($this->state[$key] ?? ''));
        }

        // Re-seed from the model so a numeric value typed as "0.250" is displayed the way the
        // billing path will actually read it back.
        foreach (['kwh_price', 'default_maintenance_fee', 'lbp_rate'] as $key) {
            $this->state[$key] = (string) Setting::get($key);
        }

        // No livewire event here: nothing listened for it, and the redirect below reloads the
        // page, so a browser event dispatched from this render could never be observed anyway.
        session()->flash('status', __('set.saved'));

        // Redirect so the status is actually SEEN: the layout renders session('status'), and a
        // Livewire update re-renders only this component — the flash was invisible here and then
        // surfaced as a stale message on whatever page the operator opened next.
        return $this->redirect(route('admin.settings'), navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.pages.settings');
    }
}
