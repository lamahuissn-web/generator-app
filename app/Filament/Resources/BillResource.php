<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BillResource\Pages;
use App\Models\Bill;
use App\Models\Subscriber;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BillResource extends Resource
{
    protected static ?string $model = Bill::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static \UnitEnum|string|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 21;

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
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => $state === Subscriber::TYPE_METERED ? 'Metered' : 'Flat')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Metered' ? 'info' : 'violet'),
                Tables\Columns\TextColumn::make('consumption')
                    ->label('kWh')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('usage_amount')
                    ->label('Usage')
                    ->money('USD')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('maintenance_fee')
                    ->label('Maintenance')
                    ->money('USD')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('flat_amount')
                    ->label('Flat')
                    ->money('USD')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('prev_debt')
                    ->label('Prev. debt')
                    ->money('USD')
                    ->color(fn (Bill $r): string => (float) $r->prev_debt > 0 ? 'danger' : 'gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('paymentStatus')
                    ->label('Status')
                    ->getStateUsing(fn (Bill $r): string => ucfirst($r->paymentStatus()))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Paid' => 'success',
                        'Partial' => 'warning',
                        default => 'danger',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period')
                    ->options(fn (): array => Bill::query()->distinct()->orderByDesc('period')->pluck('period', 'period')->all()),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        Subscriber::TYPE_METERED => 'Metered',
                        Subscriber::TYPE_FLAT => 'Flat rate',
                    ]),
                Tables\Filters\Filter::make('has_outstanding')
                    ->label('Unpaid / partially paid only')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('paid_amount', '<', 'total')),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBills::route('/'),
        ];
    }
}
