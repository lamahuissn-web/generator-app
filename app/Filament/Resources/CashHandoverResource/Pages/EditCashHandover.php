<?php

namespace App\Filament\Resources\CashHandoverResource\Pages;

use App\Filament\Resources\CashHandoverResource;
use App\Services\CashHandoverService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCashHandover extends EditRecord
{
    protected static string $resource = CashHandoverResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return CashHandoverService::update($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
