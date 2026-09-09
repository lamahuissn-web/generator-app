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
                ->label('Subscriber')
                ->relationship('subscriber', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->optionsLimit(100),
            Forms\Components\TextInput::make('period')
                ->label('Period (YYYY-MM)')
                ->helperText('e.g. 2026-09 — one reading per subscriber per period')
                ->default(now()->format('Y-m'))
                ->regex('/^\d{4}-(0[1-9]|1[0-2])$/')
                ->required(),
            Forms\Components\TextInput::make('current_reading')
                ->label('Current meter reading')
                ->numeric()
                ->minValue(0)
                ->required(),
            Forms\Components\TextInput::make('note')->maxLength(200),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('subscriber.name')
                    ->label('Subscriber')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('period')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('previous_reading')
                    ->label('Previous')
                    ->numeric(),
                Tables\Columns\TextColumn::make('current_reading')
                    ->label('Current')
                    ->numeric(),
                Tables\Columns\TextColumn::make('consumption')
                    ->label('kWh')
                    ->numeric(),
                Tables\Columns\TextColumn::make('enteredBy.name')
                    ->label('Entered by')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period')
                    ->options(fn (): array => Reading::query()->distinct()->orderByDesc('period')->pluck('period', 'period')->all()),
            ])
            ->actions([
                DeleteAction::make()
                    ->modalHeading('Delete reading & its bill?')
                    ->modalDescription('This also deletes the bill generated from this reading.'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReadings::route('/'),
            'create' => Pages\CreateReading::route('/create'),
        ];
    }
}
