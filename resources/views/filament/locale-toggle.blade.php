<form method="POST" action="{{ route('locale.switch') }}" class="flex items-center" style="margin-inline-end: 0.9rem">
    @csrf
    <input type="hidden" name="locale" value="{{ app()->isLocale('ar') ? 'en' : 'ar' }}">
    <x-filament::icon-button
        color="gray"
        type="submit"
        icon-size="lg"
        :icon="\Filament\Support\Icons\Heroicon::Language"
        :tooltip="app()->isLocale('ar') ? 'English' : 'العربية'"
        class="fi-topbar-locale-toggle"
    />
</form>
