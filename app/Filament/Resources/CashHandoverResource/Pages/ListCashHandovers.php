<?php

namespace App\Filament\Resources\CashHandoverResource\Pages;

use App\Filament\Resources\CashHandoverResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCashHandovers extends ListRecords
{
    protected static string $resource = CashHandoverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
