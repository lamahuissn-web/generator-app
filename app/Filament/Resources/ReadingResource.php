<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReadingResource\Pages;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Services\BillingService;
use Filament\Actions\DeleteAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ReadingResource extends Resource
{
    protected static ?string $model = Reading::class;

    /** Mode the reading is being recorded in: a normal read, an estimate, or a replacement. */
    public const MODE_NORMAL = 'normal';

    public const MODE_ESTIMATED = 'estimated';

    public const MODE_SWAP = 'swap';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static \UnitEnum|string|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('subscriber_id')
                ->label(__('t.subscriber'))
                ->relationship('subscriber', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->optionsLimit(100),
            Forms\Components\TextInput::make('period')
                ->label(__('t.period_ym'))
                ->helperText(__('s.period_hint'))
                ->default(now()->format('Y-m'))
                ->regex('/^\d{4}-(0[1-9]|1[0-2])$/')
                ->required()
                ->live(),

            ...self::modeFields(),
        ]);
    }

    /** The subscriber + period currently picked in the form. */
    private static function context(Get $get): ?array
    {
        $subscriber = Subscriber::find($get('subscriber_id'));
        if (! $subscriber) {
            return null;
        }

        $period = (string) ($get('period') ?: now()->format('Y-m'));

        return [$subscriber, $period, BillingService::previousValue($subscriber, $period)];
    }

    /** 'السابقة: 1,270' — what this reading continues from. */
    private static function previousHint(Get $get): ?string
    {
        $context = self::context($get);
        if (! $context) {
            return null;
        }

        [$subscriber, $period, $previous] = $context;
        if ($subscriber->readingFor($period)) {
            return __('t.reading_exists_hint', ['period' => $period]);
        }

        return __('t.previous_value_hint', ['value' => number_format($previous, 0)]);
    }

    /** @return array{kwh: float, count: int, periods: array<int,string>}|null */
    private static function suggestion(Get $get): ?array
    {
        $context = self::context($get);

        return $context ? BillingService::suggestedConsumption($context[0], $context[1]) : null;
    }

    /** Tells the operator what the average is and what meter value will be stored. */
    private static function estimateHint(Get $get): string
    {
        $context = self::context($get);
        if (! $context) {
            return __('t.estimated_hint_pick');
        }

        [$subscriber, $period, $previous] = $context;
        $suggestion = BillingService::suggestedConsumption($subscriber, $period);
        $kwh = $get('estimated_kwh');
        $kwh = is_numeric($kwh) ? (float) $kwh : (float) ($suggestion['kwh'] ?? 0);

        $average = $suggestion
            ? __('t.estimated_average', ['kwh' => number_format($suggestion['kwh'], 1), 'count' => $suggestion['count']])
            : __('t.estimated_no_history');

        return $average.' — '.__('t.estimated_stored', [
            'previous' => number_format($previous, 0),
            'kwh' => number_format($kwh, 1),
            'stored' => number_format($previous + $kwh, 0),
        ]).' '.__('t.estimated_zero_note');
    }

    /**
     * The reading form without the subscriber/period header: the mode selector plus the
     * fields of each mode. Shared with the subscriber profile's "record reading" action
     * so both entry points can record a normal, estimated or replacement reading.
     *
     * @return array<int, Forms\Components\Field>
     */
    public static function modeFields(): array
    {
        return [
            Forms\Components\Select::make('reading_mode')
                ->label(__('t.reading_mode'))
                ->options([
                    self::MODE_NORMAL => __('t.mode_normal'),
                    self::MODE_ESTIMATED => __('t.mode_estimated'),
                    self::MODE_SWAP => __('t.mode_swap'),
                ])
                ->default(self::MODE_NORMAL)
                ->required()
                ->live()
                ->helperText(__('t.reading_mode_hint')),

            Forms\Components\TextInput::make('current_reading')
                ->label(fn (Get $get): string => $get('reading_mode') === self::MODE_SWAP
                    ? __('t.new_meter_now_reading')
                    : __('t.cur_meter_reading'))
                ->numeric()
                ->minValue(0)
                ->visible(fn (Get $get): bool => $get('reading_mode') !== self::MODE_ESTIMATED)
                ->required(fn (Get $get): bool => $get('reading_mode') !== self::MODE_ESTIMATED)
                ->helperText(fn (Get $get): ?string => self::previousHint($get)),

            // --- broken meter: the operator decides the kWh -------------------------
            Forms\Components\TextInput::make('estimated_kwh')
                ->label(__('t.estimated_kwh'))
                ->numeric()
                ->minValue(0)
                ->default(fn (Get $get): ?float => self::suggestion($get)['kwh'] ?? null)
                ->visible(fn (Get $get): bool => $get('reading_mode') === self::MODE_ESTIMATED)
                ->required(fn (Get $get): bool => $get('reading_mode') === self::MODE_ESTIMATED)
                ->live(onBlur: true)
                ->helperText(fn (Get $get): string => self::estimateHint($get)),
            Forms\Components\TextInput::make('estimate_reason')
                ->label(__('t.estimate_reason'))
                ->maxLength(160)
                ->default(__('t.estimate_reason_default'))
                ->visible(fn (Get $get): bool => $get('reading_mode') === self::MODE_ESTIMATED)
                ->required(fn (Get $get): bool => $get('reading_mode') === self::MODE_ESTIMATED),

            // --- meter replacement ---------------------------------------------------
            Forms\Components\TextInput::make('old_meter_no')
                ->label(__('t.old_meter_no'))
                ->maxLength(40)
                ->default(fn (Get $get): ?string => Subscriber::find($get('subscriber_id'))?->meter_no)
                ->visible(fn (Get $get): bool => $get('reading_mode') === self::MODE_SWAP),
            Forms\Components\TextInput::make('old_meter_reading')
                ->label(__('t.old_meter_final'))
                ->numeric()
                ->minValue(0)
                ->visible(fn (Get $get): bool => $get('reading_mode') === self::MODE_SWAP)
                ->required(fn (Get $get): bool => $get('reading_mode') === self::MODE_SWAP)
                ->helperText(fn (Get $get): ?string => self::previousHint($get)),
            Forms\Components\TextInput::make('new_meter_no')
                ->label(__('t.new_meter_no'))
                ->maxLength(40)
                ->visible(fn (Get $get): bool => $get('reading_mode') === self::MODE_SWAP)
                ->required(fn (Get $get): bool => $get('reading_mode') === self::MODE_SWAP),
            Forms\Components\TextInput::make('new_meter_start')
                ->label(__('t.new_meter_start'))
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->visible(fn (Get $get): bool => $get('reading_mode') === self::MODE_SWAP)
                ->required(fn (Get $get): bool => $get('reading_mode') === self::MODE_SWAP)
                ->helperText(__('t.new_meter_start_hint')),

            Forms\Components\TextInput::make('note')->label(__('t.note'))->maxLength(200),
        ];
    }

    /**
     * Record the reading described by the form data and return the service result.
     *
     * @param  array<string,mixed>  $data  form state (reading_mode + the fields of that mode)
     * @return array{reading: Reading, bill: Bill}
     *
     * @throws \RuntimeException bubbles up from the billing service
     */
    public static function record(Subscriber $subscriber, array $data, ?int $userId): array
    {
        return match ($data['reading_mode'] ?? self::MODE_NORMAL) {
            // Broken meter: the operator's kWh is what gets billed, and it becomes the new
            // baseline — no catch-up when the real meter comes back.
            self::MODE_ESTIMATED => BillingService::enterEstimatedReading(
                $subscriber,
                $data['period'],
                (float) $data['estimated_kwh'],
                $data['estimate_reason'] ?? null,
                $userId,
            ),
            self::MODE_SWAP => BillingService::enterMeteredReading(
                $subscriber,
                $data['period'],
                (float) $data['current_reading'],
                $userId,
                $data['note'] ?? null,
                [
                    'swap' => [
                        'old_meter_no' => $data['old_meter_no'] ?? null,
                        'old_reading' => (float) $data['old_meter_reading'],
                        'new_meter_no' => $data['new_meter_no'],
                        'new_start' => (float) ($data['new_meter_start'] ?? 0),
                    ],
                ],
            ),
            default => BillingService::enterMeteredReading(
                $subscriber,
                $data['period'],
                (float) $data['current_reading'],
                $userId,
                $data['note'] ?? null,
            ),
        };
    }

    /** One-line summary of what was recorded, for the success notification. */
    public static function recordSummary(Reading $reading, float $total, float $debt): string
    {
        $parts = [__('t.reading_ok_body', ['total' => money($total), 'debt' => money($debt)])];

        if ($reading->is_estimated) {
            $parts[] = __('t.reading_ok_estimated', ['kwh' => number_format((float) $reading->consumption, 1)]);
        }
        if ($reading->hasMeterSwap()) {
            $parts[] = __('t.reading_ok_swap', ['label' => $reading->meterSwapLabel()]);
        }

        return implode(' ', $parts);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultNumberLocale('en')
            ->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('subscriber.name')->label(__('t.subscriber'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('period')->label(__('t.period'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('previous_reading')->label(__('t.prev_reading'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('current_reading')->label(__('t.current_reading'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('consumption')->label(__('t.kwh'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('is_estimated')->label(__('t.reading_source'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('t.source_estimated') : __('t.source_real'))
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('new_meter_no')->label(__('t.meter_swap'))
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (Reading $record): ?string => $record->meterSwapLabel())
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('enteredBy.name')->label(__('t.entered_by'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label(__('t.created'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('period')
                    ->options(fn (): array => Reading::query()->distinct()->orderByDesc('period')->pluck('period', 'period')->all()),
                Tables\Filters\TernaryFilter::make('is_estimated')
                    ->label(__('t.reading_source')),
            ])
            ->actions([
                DeleteAction::make()
                    ->modalHeading(__('a.del_reading_q'))
                    ->modalDescription(__('a.del_reading_desc')),
            ])
            ->bulkActions([]);
    }

    public static function getModelLabel(): string
    {
        return __('mdl.reading');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mdl.readings');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.billing');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReadings::route('/'),
            'create' => Pages\CreateReading::route('/create'),
        ];
    }
}
