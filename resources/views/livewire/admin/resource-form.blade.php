<form wire:submit="save" class="grid gap-5">
    @foreach ($sections as $section)
        @php
            // Visibility is resolved HERE, in the Livewire component's scope: a Blade
            // component view cannot see `$this->shows()` (its `$this` is the Blade component).
            $visibleFields = collect($section['fields'])->filter(fn (array $f) => $this->shows($f))->values();
            $sectionVisible = isset($section['visible_when']) ? ($section['visible_when'])($state) : true;
        @endphp

        @continue (! $sectionVisible || $visibleFields->isEmpty())

        <x-admin.card :title="$section['title'] ?? null" :description="$section['description'] ?? null"
                      content-class="sm:grid-cols-2">
            @foreach ($visibleFields as $field)
                <x-admin.field :field="$field" model="state" :values="$state" id-prefix="f" />
            @endforeach
        </x-admin.card>
    @endforeach

    <div class="flex flex-wrap items-center gap-2.5">
        <button type="submit" class="kt-btn kt-btn-lg" wire:loading.attr="disabled">
            <i class="ki-filled ki-check"></i>
            {{ __('t.save') }}
        </button>
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('admin.dashboard') }}"
           class="kt-btn kt-btn-lg kt-btn-outline">
            {{ __('t.cancel') }}
        </a>
        <span class="text-xs text-muted-foreground">{{ __('t.unsaved_hint') }}</span>
    </div>
</form>
