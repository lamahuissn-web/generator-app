<?php

namespace App\Filament\Resources\ReadingResource\Pages;

use App\Filament\Resources\ReadingResource;
use App\Models\Subscriber;
use App\Services\BillingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateReading extends CreateRecord
{
    protected static string $resource = ReadingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $subscriber = Subscriber::find($data['subscriber_id']);
        if (! $subscriber) {
            throw ValidationException::withMessages(['subscriber_id' => 'Subscriber not found.']);
        }
        if (! $subscriber->isMetered()) {
            throw ValidationException::withMessages(['subscriber_id' => 'Flat-rate subscribers have no meter readings — collect their flat fee instead.']);
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            $result = BillingService::enterMeteredReading(
                Subscriber::findOrFail($data['subscriber_id']),
                $data['period'],
                (float) $data['current_reading'],
                auth()->id(),
                $data['note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            Notification::make()->title('Could not record reading')->body($e->getMessage())->danger()->send();
            throw ValidationException::withMessages(['period' => $e->getMessage()]);
        }

        $reading = $result['reading'];
        $bill = $result['bill'];
        Notification::make()
            ->title('Reading recorded')
            ->body('Bill generated: '.money($bill->total).' ('.money($bill->prev_debt).' previous debt rolled over).')
            ->success()
            ->send();

        return $reading;
    }
}
