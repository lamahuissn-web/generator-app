<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriberResource\Pages;
use App\Filament\Resources\SubscriberResource\RelationManagers\BillsRelationManager;
use App\Filament\Resources\SubscriberResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\SubscriberResource\RelationManagers\ReadingsRelationManager;
use App\Models\Route;
use App\Models\Subscriber;
use App\Services\BillingService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static \UnitEnum|string|null $navigationGroup = 'Network';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('t.subscriber'))->columns(2)->components([
                Forms\Components\Select::make('route_id')
                    ->label(__('t.route'))
                    ->relationship('route', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')->label(__('t.route_name'))->required(),
                    ]),
                Forms\Components\TextInput::make('name')->label(__('t.name'))->required()->maxLength(150),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(30)->columnSpan(1),
                Forms\Components\TextInput::make('address')->maxLength(200)->columnSpan(1),
                Forms\Components\Select::make('type')
                    ->options([
                        Subscriber::TYPE_METERED => 'Metered (by reading)',
                        Subscriber::TYPE_FLAT => 'Flat rate (fixed)',
                    ])
                    ->default(Subscriber::TYPE_METERED)
                    ->live()
                    ->required(),
                Forms\Components\TextInput::make('meter_no')->label(__('t.meter_no'))->maxLength(40)
                    ->unique(ignoreRecord: true)
                    ->visible(fn (Get $get): bool => $get('type') === Subscriber::TYPE_METERED),
                Forms\Components\Select::make('status')
                    ->options([
                        Subscriber::STATUS_ACTIVE => 'Active',
                        Subscriber::STATUS_INACTIVE => 'Inactive',
                    ])
                    ->default(Subscriber::STATUS_ACTIVE)
                    ->required(),
            ]),
            Section::make(__('s.metered'))->description(__('s.metered_desc'))
                ->visible(fn (Get $get): bool => $get('type') === Subscriber::TYPE_METERED)
                ->columns(3)
                ->components([
                    Forms\Components\TextInput::make('amperage')->label(__('t.amperage_a'))
                        ->numeric()->integer()->minValue(1)->maxValue(200)
                        ->helperText(__('s.ampere_hint')),
                    Forms\Components\Select::make('maintenance_mode')
                        ->label(__('s.mode_label'))
                        ->options([
                            Subscriber::MAINT_AUTO => __('s.mode_auto', ['fee' => money(\App\Services\BillingService::defaultMaintenanceFee())]),
                            Subscriber::MAINT_CUSTOM => __('s.mode_custom'),
                            Subscriber::MAINT_EXEMPT => __('s.mode_exempt'),
                        ])
                        ->default(Subscriber::MAINT_AUTO)
                        ->helperText(__('s.mode_hint'))
                        ->live()
                        ->required(),
                    Forms\Components\TextInput::make('maintenance_fee')->label(__('t.maint_fee'))
                        ->numeric()->minValue(0)->default(0)->prefix('$')
                        ->visible(fn (Get $get): bool => $get('maintenance_mode') === Subscriber::MAINT_CUSTOM),
                    Forms\Components\Placeholder::make('kwh_hint')
                        ->label('')
                        ->content(fn (): string => __('s.kwh_note'))
                        ->columnSpanFull(),
                ]),
            Section::make(__('s.flat_rate'))->description(__('s.flat_desc'))
                ->visible(fn (Get $get): bool => $get('type') === Subscriber::TYPE_FLAT)
                ->columns(2)
                ->components([
                    Forms\Components\TextInput::make('flat_fee')->label(__('t.flat_fee'))
                        ->numeric()->minValue(0)->required()->prefix('$'),
                ]),
            Section::make(__('s.notes'))->components([
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale("en")
            ->defaultSort('name')
            ->recordUrl(fn (Subscriber $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('t.name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Subscriber $r) => collect([
                        $r->meter_no ? 'Meter '.$r->meter_no : null,
                        $r->amperage ? $r->amperage.'A' : null,
                    ])->filter()->implode(' · ')),
                Tables\Columns\TextColumn::make('route.name')->label(__('t.route'))
                    ->label(__('t.route'))
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('type')->label(__('t.type'))
                    ->formatStateUsing(fn (string $state): string => $state === Subscriber::TYPE_METERED ? 'Metered' : 'Flat rate')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Metered' ? 'info' : 'violet'),
                Tables\Columns\TextColumn::make('phone')->label(__('t.phone'))->searchable()->copyable()->toggleable(),
                Tables\Columns\TextColumn::make('outstanding')
                    ->label(__('t.outstanding'))
                    ->getStateUsing(fn (Subscriber $r): string => money($r->outstanding()))
                    ->badge()
                    ->color(fn (Subscriber $r): string => $r->outstanding() > 0 ? 'danger' : 'success')
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->leftJoin('bills', 'bills.subscriber_id', '=', 'subscribers.id')
                        ->groupBy('subscribers.id')
                        ->orderByRaw('COALESCE(SUM(bills.total - bills.paid_amount), 0) '.$direction)),
                Tables\Columns\TextColumn::make('status')->label(__('t.status'))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')->label(__('t.created'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('route_id')
                    ->label(__('t.route'))
                    ->relationship('route', 'name'),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        Subscriber::TYPE_METERED => 'Metered',
                        Subscriber::TYPE_FLAT => 'Flat rate',
                    ]),
                Tables\Filters\TernaryFilter::make('status')
                    ->label(__('t.status'))
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->actions([
                \Filament\Actions\Action::make('collect')
                    ->label(__('a.collect'))
                    ->icon(Heroicon::Banknotes)
                    ->color('success')
                    ->visible(fn (Subscriber $record): bool => $record->outstanding() > 0 && $record->status === Subscriber::STATUS_ACTIVE)
                    ->schema([
                        Forms\Components\TextInput::make('amount')
                            ->label(__('t.amount_received'))
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->helperText(fn (Subscriber $record): string => 'Outstanding: '.money($record->outstanding())),
                        Forms\Components\TextInput::make('note')->label(__('t.note'))->maxLength(200),
                    ])
                    ->action(function (array $data, Subscriber $record): void {
                        try {
                            $result = BillingService::collect($record, (float) $data['amount'], auth()->id(), $data['note'] ?? null);
                            Notification::make()
                                ->title('Collected '.money($result['applied']).' from '.$record->name)
                                ->body('Remaining: '.money($result['remaining']))
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Could not collect')->body($e->getMessage())->danger()->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription(fn (Subscriber $record): string => $record->bills()->exists()
                        ? 'This subscriber has billing history — deleting removes ALL their readings, bills and payments permanently. Consider setting status to Inactive instead.'
                        : 'Delete this subscriber permanently.'),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            ReadingsRelationManager::class,
            BillsRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getModelLabel(): string
    {
        return __('mdl.subscriber');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mdl.subscribers');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.network');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscribers::route('/'),
            'create' => Pages\CreateSubscriber::route('/create'),
            'view' => Pages\ViewSubscriber::route('/{record}'),
            'edit' => Pages\EditSubscriber::route('/{record}/edit'),
        ];
    }
}
