<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static \UnitEnum|string|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 22;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale("en")
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('subscriber.name')->label(__('t.subscriber'))
                    ->label(__('t.subscriber'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')->label(__('t.amount'))
                    ->money('USD')
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('collector.name')->label(__('t.collected_by'))
                    ->label(__('t.collected_by'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('note')->label(__('t.note'))
                    ->limit(30)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('t.created'))
                    ->label(__('t.when'))
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('collector_id')
                    ->label(__('f.collector'))
                    ->options(fn (): array => User::query()->where('role', 'collector')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('period')
                    ->label(__('t.payment_month'))
                    ->options(fn (): array => Payment::query()
                        ->selectRaw("substr(created_at, 1, 7) as p")
                        ->distinct()->orderByDesc('p')->pluck('p', 'p')->all()),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getModelLabel(): string
    {
        return __('mdl.payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mdl.payments');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.billing');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
        ];
    }
}
