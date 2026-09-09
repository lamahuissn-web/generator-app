<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Settings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = Heroicon::Cog6Tooth;

    protected static \UnitEnum|string|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.settings';

    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('mdl.settings');
    }

    public function mount(): void
    {
        $this->form->fill([
            'kwh_price' => (string) Setting::get('kwh_price', 0.25),
            'default_maintenance_fee' => (string) Setting::get('default_maintenance_fee', 0),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('kwh_price')
                ->label(__('set.kwh_label'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('$')
                ->helperText(__('set.kwh_helper')),
            TextInput::make('default_maintenance_fee')
                ->label(__('set.maint_label'))
                ->numeric()
                ->minValue(0)
                ->step(0.5)
                ->prefix('$')
                ->helperText(__('set.maint_helper')),
        ])->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label(__('set.save'))->action('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        Setting::set('kwh_price', (string) $state['kwh_price']);
        Setting::set('default_maintenance_fee', (string) $state['default_maintenance_fee']);

        Notification::make()->title('Settings saved')->success()->send();
    }
}
