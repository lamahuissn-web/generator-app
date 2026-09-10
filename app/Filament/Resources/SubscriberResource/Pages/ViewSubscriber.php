<?php

namespace App\Filament\Resources\SubscriberResource\Pages;

use App\Filament\Resources\SubscriberResource;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\BillingService;
use App\Services\WhatsAppService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

class ViewSubscriber extends ViewRecord
{
    protected static string $resource = SubscriberResource::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::Eye;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->components([
                TextEntry::make('outstanding')
                    ->label(__('t.outstanding_bal'))
                    ->state(fn (Subscriber $r): string => money($r->outstanding()))
                    ->color(fn (Subscriber $r): string => $r->outstanding() > 0 ? 'danger' : 'success')
                    ->size(TextSize::Large),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                TextEntry::make('type')
                    ->formatStateUsing(fn (string $state): string => $state === Subscriber::TYPE_METERED ? 'Metered' : 'Flat rate')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Metered' ? 'info' : 'violet'),
                TextEntry::make('route.name')->label(__('t.route'))->placeholder('—'),
                TextEntry::make('meter_no')->label(__('t.meter'))->placeholder('—'),
                TextEntry::make('amperage')->label(__('t.amperage'))->formatStateUsing(fn (?int $state): string => $state ? $state.'A' : '—'),
                TextEntry::make('phone')->copyable()->icon(Heroicon::Phone),
                TextEntry::make('address')->placeholder('—')->columnSpan(2),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return [
            Action::make('collect')
                ->label(__('a.collect_payment'))
                ->icon(Heroicon::Banknotes)
                ->color('success')
                ->visible(fn (): bool => $record->outstanding() > 0 && $record->status === Subscriber::STATUS_ACTIVE)
                ->schema([
                    Forms\Components\TextInput::make('amount')
                        ->label(__('t.amount_received'))
                        ->numeric()->minValue(0.01)->required()
                        ->helperText(fn (): string => 'Outstanding balance: '.money($record->outstanding())),
                    Forms\Components\Select::make('method')
                        ->label(__('t.payment_method'))
                        ->options(Payment::methodOptions())
                        ->default(Payment::METHOD_CASH_USD)
                        ->required(),
                    Forms\Components\TextInput::make('note')->label(__('t.note'))->maxLength(200),
                ])
                ->action(function (array $data): void {
                    $record = $this->getRecord();
                    try {
                        $result = BillingService::collect(
                            $record,
                            (float) $data['amount'],
                            auth()->id(),
                            $data['note'] ?? null,
                            null,
                            $data['method'] ?? Payment::METHOD_CASH_USD,
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
                                Action::make('send_receipt')->label(__('portal.send_receipt'))->url($wa)->openUrlInNewTab(),
                            ]);
                        }
                        $notification->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not collect')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('recordReading')
                ->label(__('a.record_reading'))
                ->icon(Heroicon::ClipboardDocumentList)
                ->color('info')
                ->visible(fn (): bool => $record->isMetered() && $record->status === Subscriber::STATUS_ACTIVE)
                ->schema([
                    Forms\Components\TextInput::make('period')
                        ->label(__('t.period_ym'))
                        ->default(BillingService::currentPeriod())
                        ->regex(BillingService::PERIOD_REGEX)
                        ->required(),
                    Forms\Components\TextInput::make('current_reading')
                        ->label(__('t.cur_meter_reading'))
                        ->numeric()->minValue(0)->required(),
                    Forms\Components\TextInput::make('note')->label(__('t.note'))->maxLength(200),
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
            Action::make('statement')
                ->label(__('col.statement'))
                ->icon(Heroicon::DocumentText)
                ->color('gray')
                ->url(fn (): string => route('print.statement', ['subscriber' => $record->id, 'size' => 'a4']))
                ->openUrlInNewTab(),
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
