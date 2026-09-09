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
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('subscriber.name')
                    ->label('Subscriber')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('USD')
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('collector.name')
                    ->label('Collected by')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('note')
                    ->limit(30)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('collector_id')
                    ->label('Collector')
                    ->options(fn (): array => User::query()->where('role', 'collector')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('period')
                    ->label('Payment month')
                    ->options(fn (): array => Payment::query()
                        ->selectRaw("substr(created_at, 1, 7) as p")
                        ->distinct()->orderByDesc('p')->pluck('p', 'p')->all()),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
        ];
    }
}
