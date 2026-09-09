<?php

namespace App\Filament\Resources\SubscriberResource\RelationManagers;

use App\Models\Payment;
use App\Services\BillingService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('mdl.payments');
    }

    protected static string | \BackedEnum | null $icon = Heroicon::Banknotes;

    public function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale("en")
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('amount')->label(__('t.amount'))->money('USD')->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('collector.name')->label(__('t.collected_by'))->placeholder('—'),
                Tables\Columns\TextColumn::make('note')->label(__('t.note'))->limit(30)->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('t.when'))->dateTime('M j, Y H:i')->sortable(),
            ])
            ->actions([
                Action::make('reversePayment')
                    ->label(__('a.reverse_payment'))
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Correction: restores the amount to the subscriber balance and deletes this payment. Audited.')
                    ->action(function (Payment $record): void {
                        BillingService::reversePayment($record);
                        Notification::make()->title('Payment reversed')->body('Amount restored to the subscriber balance.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
