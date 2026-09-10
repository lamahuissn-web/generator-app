<?php

namespace App\Filament\Resources\CashHandoverResource\Pages;

use App\Filament\Resources\CashHandoverResource;
use App\Services\CashHandoverService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCashHandover extends CreateRecord
{
    protected static string $resource = CashHandoverResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return CashHandoverService::create($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
