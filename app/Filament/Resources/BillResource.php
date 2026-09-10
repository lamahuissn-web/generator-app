<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BillResource\Pages;
use App\Models\Bill;
use App\Models\Subscriber;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component as Livewire;

class BillResource extends Resource
{
    protected static ?string $model = Bill::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static \UnitEnum|string|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 21;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale('en')
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label(__('t.bill_no'))
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscriber.name')->label(__('t.subscriber'))
                    ->label(__('t.subscriber'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('period')->label(__('t.period'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')->label(__('t.type'))
                    ->label(__('t.type'))
                    ->formatStateUsing(fn (string $state): string => $state === Subscriber::TYPE_METERED ? 'Metered' : 'Flat')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Metered' ? 'info' : 'violet'),
                Tables\Columns\TextColumn::make('consumption')->label(__('t.kwh'))
                    ->label(__('t.kwh'))
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reading_span')->label(__('gen.col_reading_span'))
                    ->state(fn (Bill $record): string => $record->reading
                        ? number_format((float) $record->reading->previous_reading, 0).' → '.number_format((float) $record->reading->current_reading, 0)
                        : '—')
                    ->extraCellAttributes(['class' => 'gx-ltr'])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('usage_amount')->label(__('t.usage'))
                    ->label(__('t.usage'))
                    ->money('USD')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('maintenance_fee')->label(__('t.maintenance'))
                    ->label(__('t.maintenance'))
                    ->money('USD')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('flat_amount')->label(__('t.flat'))
                    ->label(__('t.flat'))
                    ->money('USD')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('prev_debt')->label(__('t.prev_debt'))
                    ->label(__('t.prev_debt'))
                    ->money('USD')
                    ->color(fn (Bill $r): string => (float) $r->prev_debt > 0 ? 'danger' : 'gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total')->label(__('t.total'))
                    ->label(__('t.total'))
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('paid_amount')->label(__('t.paid'))
                    ->label(__('t.paid'))
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('paymentStatus')
                    ->label(__('t.status'))
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
                    ->label(__('f.unpaid_only'))
                    ->query(fn (Builder $query): Builder => $query->whereColumn('paid_amount', '<', 'total')),
            ])
            ->headerActions([
                Action::make('print_filtered')
                    ->label(__('prt.print_filtered'))
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(function (Livewire $livewire) {
                        $ids = $livewire->getFilteredTableQuery()->pluck('bills.id')->implode(',');

                        return route('print.bills', ['ids' => $ids, 'size' => config('pdf.default_bill_size')]);
                    })
                    ->openUrlInNewTab(),
                Action::make('pdf_filtered')
                    ->label(__('prt.pdf_filtered'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(function (Livewire $livewire) {
                        $ids = $livewire->getFilteredTableQuery()->pluck('bills.id')->implode(',');

                        return route('print.bills', ['ids' => $ids, 'size' => config('pdf.default_bill_size'), 'format' => 'pdf']);
                    })
                    ->openUrlInNewTab(),
            ])
            ->actions([
                Action::make('print')
                    ->label(__('prt.print'))
                    ->icon('heroicon-o-printer')
                    ->url(fn (Bill $record): string => route('print.bill', ['bill' => $record->id, 'size' => config('pdf.default_bill_size')]))
                    ->openUrlInNewTab(),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Bill $record): string => route('print.bill.pdf', ['bill' => $record->id, 'size' => config('pdf.default_bill_size')]))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                BulkAction::make('print_selected')
                    ->label(__('prt.print_selected'))
                    ->icon('heroicon-o-printer')
                    ->action(fn (Collection $records) => redirect()->to(route('print.bills', [
                        'ids' => $records->pluck('id')->implode(','),
                        'size' => config('pdf.default_bill_size'),
                    ]))),
                BulkAction::make('pdf_selected')
                    ->label(__('prt.pdf_selected'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (Collection $records) => redirect()->to(route('print.bills', [
                        'ids' => $records->pluck('id')->implode(','),
                        'size' => config('pdf.default_bill_size'),
                        'format' => 'pdf',
                    ]))),
                BulkAction::make('zip_selected')
                    ->label(__('prt.zip_selected'))
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->action(fn (Collection $records) => redirect()->to(route('print.bills', [
                        'ids' => $records->pluck('id')->implode(','),
                        'size' => config('pdf.default_bill_size'),
                        'format' => 'zip',
                    ]))),
            ]);
    }

    public static function getModelLabel(): string
    {
        return __('mdl.bill');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mdl.bills');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.billing');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBills::route('/'),
        ];
    }
}
