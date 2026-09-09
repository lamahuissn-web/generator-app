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

    public function mount(): void
    {
        $this->form->fill([
            'kwh_price' => (string) Setting::get('kwh_price', 0.25),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('kwh_price')
                ->label('kWh price (USD)')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('$')
                ->helperText('Price per kWh applied to NEW metered bills. Existing bills keep the price they were created with.'),
        ])->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save settings')->action('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        Setting::set('kwh_price', (string) $state['kwh_price']);

        Notification::make()->title('Settings saved')->success()->send();
    }
}
