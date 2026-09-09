<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use App\Services\ExpenseService;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static \UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Expense')->columns(2)->components([
                Forms\Components\Select::make('category')
                    ->options(Expense::$categories)
                    ->live()
                    ->required(),
                Forms\Components\DatePicker::make('expense_date')
                    ->label('Date')
                    ->default(now())
                    ->required(),
                Forms\Components\TextInput::make('amount')
                    ->label('Amount (USD)')
                    ->numeric()->minValue(0.01)->required()->prefix('$'),
                Forms\Components\TextInput::make('quantity')
                    ->label('Quantity (liters)')
                    ->numeric()->minValue(0)
                    ->helperText('Fuel purchases: how many liters were bought')
                    ->visible(fn (Get $get): bool => $get('category') === Expense::CAT_FUEL),
                Forms\Components\TextInput::make('description')->label('Description')->maxLength(200)->columnSpanFull(),
                Forms\Components\FileUpload::make('receipt_path')
                    ->label('Receipt photo')
                    ->image()
                    ->imageEditor()
                    ->directory('expense-receipts')
                    ->maxSize(4096)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('expense_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('expense_date')->label('Date')->date('M j, Y')->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->formatStateUsing(fn (string $state): string => Expense::$categories[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Expense::CAT_FUEL => 'warning',
                        Expense::CAT_SALARY => 'info',
                        Expense::CAT_RENT => 'violet',
                        Expense::CAT_REPAIR => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('description')->limit(40)->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('quantity')->label('Liters')->numeric()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('amount')->label('Amount')->money('USD')->sortable()->weight('bold'),
                Tables\Columns\ImageColumn::make('receipt_path')->label('Receipt')->circular()->size(32)->toggleable(),
                Tables\Columns\TextColumn::make('enteredBy.name')->label('By')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options(Expense::$categories),
                Tables\Filters\Filter::make('expense_date')
                    ->label('Month')
                    ->form([
                        Forms\Components\Select::make('month')
                            ->label('Show month')
                            ->options(fn (): array => Expense::query()
                                ->selectRaw("substr(expense_date, 1, 7) as m")
                                ->distinct()->orderByDesc('m')->pluck('m', 'm')->all()),
                    ])
                    ->query(fn ($query, array $data) => $data['month']
                        ? $query->where('expense_date', 'like', $data['month'].'%')
                        : $query),
            ])
            ->actions([
                EditAction::make(),
                \Filament\Actions\Action::make('delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Expense $record): void {
                        ExpenseService::delete($record);
                        Notification::make()->title('Expense deleted')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
