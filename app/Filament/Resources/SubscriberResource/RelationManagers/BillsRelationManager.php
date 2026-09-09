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

    protected static ?string $title = 'Bills';

    protected static string | \BackedEnum | null $icon = Heroicon::ReceiptPercent;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')->label('Period')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => $state === Subscriber::TYPE_METERED ? 'Metered' : 'Flat')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Metered' ? 'info' : 'violet'),
                Tables\Columns\TextColumn::make('consumption')->label('kWh')->numeric()->toggleable(),
                Tables\Columns\TextColumn::make('total')->label('Total')->money('USD')->sortable(),
                Tables\Columns\TextColumn::make('prev_debt')->label('Prev debt')->money('USD')->toggleable(),
                Tables\Columns\TextColumn::make('paid_amount')->label('Paid')->money('USD'),
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
            ->actions([
                Action::make('deleteFlatBill')
                    ->label('Delete bill')
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
