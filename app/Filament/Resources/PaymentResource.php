<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Livewire\Component as Livewire;

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
            ->headerActions([
                Action::make('print_filtered')
                    ->label(__('prt.print_receipts_filtered'))
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(function (Livewire $livewire) {
                        $ids = $livewire->getFilteredTableQuery()->pluck('payments.id')->implode(',');

                        return route('print.receipts', ['ids' => $ids, 'size' => config('pdf.default_receipt_size')]);
                    })
                    ->openUrlInNewTab(),
                Action::make('pdf_filtered')
                    ->label(__('prt.pdf_filtered'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(function (Livewire $livewire) {
                        $ids = $livewire->getFilteredTableQuery()->pluck('payments.id')->implode(',');

                        return route('print.receipts', ['ids' => $ids, 'size' => config('pdf.default_receipt_size'), 'format' => 'pdf']);
                    })
                    ->openUrlInNewTab(),
            ])
            ->actions([
                Action::make('receipt')
                    ->label(__('prt.receipt'))->icon('heroicon-o-receipt-percent')
                    ->url(fn (Payment $record): string => route('print.receipt', ['payment' => $record->id, 'size' => config('pdf.default_receipt_size')]))
                    ->openUrlInNewTab(),
                Action::make('receipt_a5')
                    ->label('A5')->icon('heroicon-o-document-text')
                    ->url(fn (Payment $record): string => route('print.receipt', ['payment' => $record->id, 'size' => 'a5']))
                    ->openUrlInNewTab(),
                Action::make('receipt_pdf')
                    ->label('PDF')->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Payment $record): string => route('print.receipt.pdf', ['payment' => $record->id, 'size' => config('pdf.default_receipt_size')]))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                BulkAction::make('print_selected')
                    ->label(__('prt.print_selected'))
                    ->icon('heroicon-o-printer')
                    ->action(fn (Collection $records) => redirect()->to(route('print.receipts', [
                        'ids' => $records->pluck('id')->implode(','),
                        'size' => config('pdf.default_receipt_size'),
                    ]))),
                BulkAction::make('pdf_selected')
                    ->label(__('prt.pdf_selected'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (Collection $records) => redirect()->to(route('print.receipts', [
                        'ids' => $records->pluck('id')->implode(','),
                        'size' => config('pdf.default_receipt_size'),
                        'format' => 'pdf',
                    ]))),
            ]);
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
