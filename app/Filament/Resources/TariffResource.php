<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TariffResource\Pages;
use App\Models\Tariff;
use App\Services\BillingService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The month's kWh price (Lebanon's MoEW tariff changes every cycle).
 * A period without a row here falls back to the global price in Settings.
 */
class TariffResource extends Resource
{
    protected static ?string $model = Tariff::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static \UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 4;

    /** @return array<string,string> recent + upcoming months */
    public static function periodOptions(): array
    {
        $options = [];
        for ($i = 6; $i >= -3; $i--) {
            $key = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $options[$key] = app()->isLocale('ar') ? period_label_ar($key) : period_label($key);
        }

        return $options;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('trf.section'))->columns(2)->components([
                Forms\Components\Select::make('period')
                    ->label(__('trf.period'))
                    ->options(self::periodOptions())
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText(__('trf.period_hint')),
                Forms\Components\TextInput::make('kwh_price')
                    ->label(__('trf.price'))
                    ->numeric()->minValue(0)->step(0.01)->required()->prefix('$')
                    ->helperText(fn (): string => __('trf.price_hint', ['amount' => money(BillingService::kwhPrice())])),
                Forms\Components\TextInput::make('note')
                    ->label(__('trf.note'))
                    ->maxLength(200)
                    ->columnSpanFull()
                    ->placeholder(__('trf.note_ph')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale('en')
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')->label(__('trf.period'))
                    ->formatStateUsing(fn (string $state): string => app()->isLocale('ar') ? period_label_ar($state) : period_label($state))
                    ->description(fn (Tariff $record): string => $record->period)
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('kwh_price')->label(__('trf.price'))
                    ->money('USD')->weight('bold')
                    ->sortable(),
                Tables\Columns\TextColumn::make('note')->label(__('trf.note'))->limit(40)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('createdBy.name')->label(__('trf.set_by'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label(__('t.when'))->dateTime('M j, Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getModelLabel(): string
    {
        return __('mdl.tariff');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mdl.tariffs');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.finance');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTariffs::route('/'),
            'create' => Pages\CreateTariff::route('/create'),
            'edit' => Pages\EditTariff::route('/{record}/edit'),
        ];
    }
}
