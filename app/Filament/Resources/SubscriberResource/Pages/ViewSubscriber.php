<?php

namespace App\Filament\Resources\SubscriberResource\Pages;

use App\Filament\Resources\SubscriberResource;
use App\Models\Subscriber;
use App\Services\BillingService;
use App\Services\WhatsAppService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSubscriber extends ViewRecord
{
    protected static string $resource = SubscriberResource::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::Eye;

    public function infolist(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Section::make()->columns(4)->components([
                \Filament\Infolists\Components\TextEntry::make('outstanding')
                    ->label('Outstanding balance')
                    ->state(fn (Subscriber $r): string => money($r->outstanding()))
                    ->color(fn (Subscriber $r): string => $r->outstanding() > 0 ? 'danger' : 'success')
                    ->size(\Filament\Support\Enums\TextSize::Large),
                \Filament\Infolists\Components\TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                \Filament\Infolists\Components\TextEntry::make('type')
                    ->formatStateUsing(fn (string $state): string => $state === Subscriber::TYPE_METERED ? 'Metered' : 'Flat rate')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Metered' ? 'info' : 'violet'),
                \Filament\Infolists\Components\TextEntry::make('route.name')->label('Route')->placeholder('—'),
                \Filament\Infolists\Components\TextEntry::make('meter_no')->label('Meter')->placeholder('—'),
                \Filament\Infolists\Components\TextEntry::make('amperage')->label('Amperage')->formatStateUsing(fn (?int $state): string => $state ? $state.'A' : '—'),
                \Filament\Infolists\Components\TextEntry::make('phone')->copyable()->icon(Heroicon::Phone),
                \Filament\Infolists\Components\TextEntry::make('address')->placeholder('—')->columnSpan(2),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return [
            Action::make('collect')
                ->label('Collect payment')
                ->icon(Heroicon::Banknotes)
                ->color('success')
                ->visible(fn (): bool => $record->outstanding() > 0 && $record->status === Subscriber::STATUS_ACTIVE)
                ->schema([
                    Forms\Components\TextInput::make('amount')
                        ->label('Amount received (USD)')
                        ->numeric()->minValue(0.01)->required()
                        ->helperText(fn (): string => 'Outstanding balance: '.money($record->outstanding())),
                    Forms\Components\TextInput::make('note')->label('Note')->maxLength(200),
                ])
                ->action(function (array $data): void {
                    $record = $this->getRecord();
                    try {
                        $result = BillingService::collect(
                            $record,
                            (float) $data['amount'],
                            auth()->id(),
                            $data['note'] ?? null,
                        );

                        $bill = $record->bills()->orderByDesc('period')->first();
                        $phone = $record->phone;
                        $wa = null;
                        if ($phone) {
                            $text = WhatsAppService::receiptText($record, $bill, $result['applied'], $result['remaining'], 'ar');
                            $wa = WhatsAppService::waLink($phone, $text);
                        }

                        $notification = Notification::make()
                            ->title('Payment recorded — '.money($result['applied']))
                            ->body('Remaining balance: '.money($result['remaining']).($result['excess'] > 0 ? ' (excess '.money($result['excess']).' not applied)' : ''))
                            ->success();
                        if ($wa) {
                            $notification->actions([
                                Action::make('send_receipt')->label('Send receipt via WhatsApp')->url($wa)->openUrlInNewTab(),
                            ]);
                        }
                        $notification->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not collect')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('recordReading')
                ->label('Record reading')
                ->icon(Heroicon::ClipboardDocumentList)
                ->color('info')
                ->visible(fn (): bool => $record->isMetered() && $record->status === Subscriber::STATUS_ACTIVE)
                ->schema([
                    Forms\Components\TextInput::make('period')
                        ->label('Period (YYYY-MM)')
                        ->default(BillingService::currentPeriod())
                        ->regex(BillingService::PERIOD_REGEX)
                        ->required(),
                    Forms\Components\TextInput::make('current_reading')
                        ->label('Current meter reading')
                        ->numeric()->minValue(0)->required(),
                    Forms\Components\TextInput::make('note')->label('Note')->maxLength(200),
                ])
                ->action(function (array $data): void {
                    try {
                        BillingService::enterMeteredReading(
                            $this->getRecord(),
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
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
