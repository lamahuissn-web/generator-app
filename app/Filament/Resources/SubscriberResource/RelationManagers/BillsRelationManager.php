<?php

namespace App\Filament\Resources\SubscriberResource\RelationManagers;

use App\Models\Bill;
use App\Models\Subscriber;
use App\Services\BillingService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

class BillsRelationManager extends RelationManager
{
    protected static string $relationship = 'bills';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('mdl.bills');
    }

    protected static string | \BackedEnum | null $icon = Heroicon::ReceiptPercent;

    public function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale("en")
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')->label(__('t.period'))->sortable()->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('t.type'))
                    ->formatStateUsing(fn (string $state): string => $state === Subscriber::TYPE_METERED ? 'Metered' : 'Flat')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Metered' ? 'info' : 'violet'),
                Tables\Columns\TextColumn::make('consumption')->label(__('t.kwh'))->numeric()->toggleable(),
                Tables\Columns\TextColumn::make('total')->label(__('t.total'))->money('USD')->sortable(),
                Tables\Columns\TextColumn::make('prev_debt')->label(__('t.prev_debt'))->money('USD')->toggleable(),
                Tables\Columns\TextColumn::make('paid_amount')->label(__('t.paid'))->money('USD'),
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
            ->actions([
                Action::make('deleteFlatBill')
                    ->label(__('a.del_bill'))
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Correction: removes this flat-rate bill. Only possible while unpaid.')
                    ->visible(fn (Bill $record): bool => $record->type === Subscriber::TYPE_FLAT && (float) $record->paid_amount <= 0)
                    ->action(function (Bill $record): void {
                        try {
                            BillingService::deleteFlatBill($record);
                            Notification::make()->title('Bill deleted')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Cannot delete')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }
}
