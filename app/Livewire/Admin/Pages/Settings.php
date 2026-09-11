<?php

namespace App\Livewire\Admin\Pages;

use App\Models\Setting;
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
        return ['kwh_price' => '0.25', 'default_maintenance_fee' => '0'];
    }

    /** Validation for the numeric keys: a negative kWh price would silently zero every bill. */
    public function rules(): array
    {
        return [
            'state.kwh_price' => ['required', 'numeric', 'min:0'],
            'state.default_maintenance_fee' => ['required', 'numeric', 'min:0'],
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
        foreach (['kwh_price', 'default_maintenance_fee'] as $key) {
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
