<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReadingResource\Pages;
use App\Models\Reading;
use App\Models\Subscriber;
use Filament\Actions\DeleteAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ReadingResource extends Resource
{
    protected static ?string $model = Reading::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static \UnitEnum|string|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('subscriber_id')
                ->label(__('t.subscriber'))
                ->relationship('subscriber', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->optionsLimit(100),
            Forms\Components\TextInput::make('period')
                ->label(__('t.period_ym'))
                ->helperText(__('s.period_hint'))
                ->default(now()->format('Y-m'))
                ->regex('/^\d{4}-(0[1-9]|1[0-2])$/')
                ->required(),
            Forms\Components\TextInput::make('current_reading')
                ->label(__('t.cur_meter_reading'))
                ->numeric()
                ->minValue(0)
                ->required(),
            Forms\Components\TextInput::make('note')->maxLength(200),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale("en")
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('subscriber.name')->label(__('t.subscriber'))
                    ->label(__('t.subscriber'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('period')->label(__('t.period'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('previous_reading')->label(__('t.prev_reading'))
                    ->label(__('t.prev_reading'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('current_reading')->label(__('t.current_reading'))
                    ->label(__('t.current_reading'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('consumption')->label(__('t.kwh'))
                    ->label(__('t.kwh'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('enteredBy.name')->label(__('t.entered_by'))
                    ->label(__('t.entered_by'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label(__('t.created'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period')
                    ->options(fn (): array => Reading::query()->distinct()->orderByDesc('period')->pluck('period', 'period')->all()),
            ])
            ->actions([
                DeleteAction::make()
                    ->modalHeading(__('a.del_reading_q'))
                    ->modalDescription(__('a.del_reading_desc')),
            ])
            ->bulkActions([]);
    }

    public static function getModelLabel(): string
    {
        return __('mdl.reading');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mdl.readings');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.billing');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReadings::route('/'),
            'create' => Pages\CreateReading::route('/create'),
        ];
    }
}
