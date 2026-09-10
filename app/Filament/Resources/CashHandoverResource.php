<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashHandoverResource\Pages;
use App\Models\CashHandover;
use App\Models\User;
use App\Services\CashHandoverService;
use App\Services\CollectionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class CashHandoverResource extends Resource
{
    protected static ?string $model = CashHandover::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static \UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 6;

    /** @return array<int,string> */
    public static function collectorOptions(): array
    {
        return User::query()->where('role', User::ROLE_COLLECTOR)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('col.handover_section'))->columns(2)->components([
                Forms\Components\Select::make('collector_id')
                    ->label(__('col.collector'))
                    ->options(self::collectorOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get): string => $get('collector_id')
                        ? __('col.in_hand_helper', ['amount' => money(CollectionService::inHand((int) $get('collector_id')))])
                        : ''),
                Forms\Components\DatePicker::make('handover_date')
                    ->label(__('col.date'))
                    ->default(now())
                    ->required(),
                Forms\Components\TextInput::make('amount')
                    ->label(__('col.amount'))
                    ->numeric()->minValue(0.01)->required()->prefix('$'),
                Forms\Components\TextInput::make('note')
                    ->label(__('col.note'))
                    ->maxLength(200),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale('en')
            ->defaultSort('handover_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('handover_date')->label(__('t.date'))->date('M j, Y')->sortable(),
                Tables\Columns\TextColumn::make('collector.name')->label(__('col.collector'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('amount')->label(__('t.amount'))->money('USD')->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('note')->label(__('t.note'))->limit(36)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('recordedBy.name')->label(__('col.recorded_by'))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('t.when'))->dateTime('M j, Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('collector_id')
                    ->label(__('col.collector'))
                    ->options(self::collectorOptions()),
                Tables\Filters\Filter::make('month')
                    ->label(__('col.month'))
                    ->form([
                        Forms\Components\Select::make('month')
                            ->label(__('t.show_month'))
                            ->options(fn (): array => CashHandover::query()
                                ->selectRaw('substr(handover_date, 1, 7) as m')
                                ->distinct()->orderByDesc('m')->pluck('m', 'm')->all()),
                    ])
                    ->query(fn ($query, array $data) => $data['month']
                        ? $query->where('handover_date', 'like', $data['month'].'%')
                        : $query),
            ])
            ->actions([
                EditAction::make(),
                Action::make('delete')
                    ->label(__('t.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (CashHandover $record): string => __('col.delete_warning', [
                        'amount' => money($record->amount),
                        'name' => $record->collector?->name ?? __('col.unknown_collector'),
                        'in_hand' => money(CollectionService::inHand($record->collector_id) + (float) $record->amount),
                    ]))
                    ->action(function (CashHandover $record): void {
                        CashHandoverService::delete($record);
                        Notification::make()->title(__('col.deleted'))->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getModelLabel(): string
    {
        return __('mdl.handover');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mdl.handovers');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.finance');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashHandovers::route('/'),
            'create' => Pages\CreateCashHandover::route('/create'),
            'edit' => Pages\EditCashHandover::route('/{record}/edit'),
        ];
    }
}
