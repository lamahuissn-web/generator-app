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

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('mdl.readings');
    }

    protected static string | \BackedEnum | null $icon = Heroicon::ClipboardDocumentList;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === \App\Models\Subscriber::TYPE_METERED;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale("en")
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')->label(__('t.period'))->sortable()->searchable(),
                Tables\Columns\TextColumn::make('previous_reading')->label(__('t.prev_reading'))->numeric(),
                Tables\Columns\TextColumn::make('current_reading')->label(__('t.current_reading'))->numeric(),
                Tables\Columns\TextColumn::make('consumption')->label(__('t.kwh'))->numeric(),
                Tables\Columns\TextColumn::make('enteredBy.name')->label(__('t.entered_by'))->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label(__('t.when'))->dateTime('M j, Y H:i')->toggleable(),
            ])
            ->headerActions([
                Action::make('recordReading')
                    ->label(__('a.record_reading'))
                    ->icon(Heroicon::Plus)
                    ->schema([
                        Forms\Components\TextInput::make('period')
                            ->label(__('t.period_ym'))
                            ->default(\App\Services\BillingService::currentPeriod())
                            ->regex(\App\Services\BillingService::PERIOD_REGEX)
                            ->required(),
                        Forms\Components\TextInput::make('current_reading')
                            ->label(__('t.cur_meter_reading'))
                            ->numeric()->minValue(0)->required(),
                        Forms\Components\TextInput::make('note')->label(__('t.note'))->maxLength(200),
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
                    ->label(__('a.del_reading'))
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
