<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static \UnitEnum|string|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->label(__('t.full_name'))->required()->maxLength(255),
            Forms\Components\TextInput::make('email')->email()->unique(ignoreRecord: true)->required(),
            Forms\Components\Select::make('role')
                ->options([
                    User::ROLE_ADMIN => 'Admin (desktop panel)',
                    User::ROLE_COLLECTOR => 'Collector (mobile portal)',
                ])
                ->default(User::ROLE_COLLECTOR)
                ->required(),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(30),
            Forms\Components\TextInput::make('password')
                ->password()
                ->revealable()
                ->helperText(__('s.pw_hint'))
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->required(fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale("en")
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('t.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label(__('t.email'))->searchable(),
                Tables\Columns\TextColumn::make('role')->label(__('t.role'))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'admin' ? 'warning' : 'info'),
                Tables\Columns\TextColumn::make('phone')->label(__('t.phone'))->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('t.created'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()->hidden(fn (User $record): bool => $record->is(auth()->user())),
            ])
            ->bulkActions([]);
    }

    public static function getModelLabel(): string
    {
        return __('mdl.user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mdl.users');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.system');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
