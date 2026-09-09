<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RouteResource\Pages;
use App\Models\Route;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class RouteResource extends Resource
{
    protected static ?string $model = Route::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-map';

    protected static \UnitEnum|string|null $navigationGroup = 'Network';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->label(__('t.route_area_name'))->required()->maxLength(120),
            Forms\Components\Textarea::make('notes')->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale("en")
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('t.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('active_subscribers_count')
                    ->label(__('t.active_subs'))
                    ->state(fn (Route $r): string => (string) $r->subscribers()->where('status', 'active')->count()),
                Tables\Columns\TextColumn::make('notes')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('t.created'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getModelLabel(): string
    {
        return __('mdl.route');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mdl.routes');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.network');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoutes::route('/'),
            'create' => Pages\CreateRoute::route('/create'),
            'edit' => Pages\EditRoute::route('/{record}/edit'),
        ];
    }
}
