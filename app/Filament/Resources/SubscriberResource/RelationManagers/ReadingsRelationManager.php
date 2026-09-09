<?php

namespace App\Filament\Resources\SubscriberResource\RelationManagers;

use App\Models\Reading;
use App\Services\BillingService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'readings';

    protected static ?string $title = 'Meter readings';

    protected static string | \BackedEnum | null $icon = Heroicon::ClipboardDocumentList;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === \App\Models\Subscriber::TYPE_METERED;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')->label('Period')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('previous_reading')->label('Previous')->numeric(),
                Tables\Columns\TextColumn::make('current_reading')->label('Current')->numeric(),
                Tables\Columns\TextColumn::make('consumption')->label('kWh')->numeric(),
                Tables\Columns\TextColumn::make('enteredBy.name')->label('Recorded by')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('M j, Y H:i')->toggleable(),
            ])
            ->headerActions([
                Action::make('recordReading')
                    ->label('Record reading')
                    ->icon(Heroicon::Plus)
                    ->schema([
                        Forms\Components\TextInput::make('period')
                            ->label('Period (YYYY-MM)')
                            ->default(\App\Services\BillingService::currentPeriod())
                            ->regex(\App\Services\BillingService::PERIOD_REGEX)
                            ->required(),
                        Forms\Components\TextInput::make('current_reading')
                            ->label('Current meter reading')
                            ->numeric()->minValue(0)->required(),
                        Forms\Components\TextInput::make('note')->label('Note')->maxLength(200),
                    ])
                    ->action(function (array $data): void {
                        try {
                            BillingService::enterMeteredReading(
                                $this->getOwnerRecord(),
                                $data['period'],
                                (float) $data['current_reading'],
                                auth()->id(),
                                $data['note'] ?? null,
                            );
                            Notification::make()->title('Reading recorded & bill generated')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Could not record reading')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->actions([
                Action::make('deleteReading')
                    ->label('Delete reading & bill')
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Correction: removes this reading and its bill. Only possible while the bill is unpaid.')
                    ->action(function (Reading $record): void {
                        try {
                            BillingService::deleteReadingAndBill($record);
                            Notification::make()->title('Reading and its bill deleted')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Cannot delete')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }
}
