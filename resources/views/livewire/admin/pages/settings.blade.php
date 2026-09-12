<form wire:submit="save" class="grid gap-5">
    {{-- The kWh price matters more than the rest: with no monthly tariff row, every invoice
         prices from here, so the helper text under the field says so. --}}
    <x-admin.card :title="__('set.billing_section')" content-class="sm:grid-cols-2">
        @foreach (['kwh_price', 'default_maintenance_fee', 'bill_rounding'] as $key)
            <x-admin.field :field="$this->keys()[$key]" model="state" :values="$state" id-prefix="set" />
        @endforeach
    </x-admin.card>

    <x-admin.card :title="__('set.company_section')" :description="__('set.company_hint')" content-class="sm:grid-cols-2">
        @foreach (['company_name', 'company_phone', 'company_address', 'bill_footer'] as $key)
            <x-admin.field :field="$this->keys()[$key]" model="state" :values="$state" id-prefix="set" />
        @endforeach
    </x-admin.card>

    {{-- The lira rate: reads as one more billing number, because that is what it is — the
         conversions the office quotes at the door come from here. --}}
    <x-admin.card :title="__('set.currency_section')" :description="__('set.currency_hint')" content-class="sm:grid-cols-2">
        <x-admin.field :field="$this->keys()['lbp_rate']" model="state" :values="$state" id-prefix="set" />
    </x-admin.card>

    <div class="flex flex-wrap items-center gap-2.5">
        <button type="submit" class="kt-btn kt-btn-lg" wire:loading.attr="disabled" wire:target="save">
            <i class="ki-filled ki-check"></i>
            {{ __('set.save') }}
        </button>
    </div>
</form>
