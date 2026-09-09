<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static \UnitEnum|string|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $label = 'Audit log';

    protected static ?string $pluralLabel = 'Audit log';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('M j, Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('actor.name')->label('By')->placeholder('System')->sortable(),
                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'reading.created', 'payment.created' => 'success',
                        'payment.reversed', 'reading.deleted', 'bill.deleted' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('subject_type')->label('Subject')->formatStateUsing(fn (?string $state): string => class_basename((string) $state))->placeholder('—'),
                Tables\Columns\TextColumn::make('subject_id')->label('ID')->placeholder('—'),
                Tables\Columns\TextColumn::make('payload')
                    ->label('Details')
                    ->formatStateUsing(fn ($state): string => is_array($state) && $state !== []
                        ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : '—')
                    ->limit(90)
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->options([
                        'reading.created' => 'Reading created',
                        'payment.created' => 'Payment created',
                        'reading.deleted' => 'Reading deleted',
                        'bill.deleted' => 'Bill deleted',
                        'payment.reversed' => 'Payment reversed',
                    ]),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
