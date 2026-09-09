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

    protected static ?string $title = 'Payments';

    protected static string | \BackedEnum | null $icon = Heroicon::Banknotes;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('amount')->label('Amount')->money('USD')->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('collector.name')->label('Collected by')->placeholder('—'),
                Tables\Columns\TextColumn::make('note')->label('Note')->limit(30)->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('M j, Y H:i')->sortable(),
            ])
            ->actions([
                Action::make('reversePayment')
                    ->label('Reverse payment')
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
