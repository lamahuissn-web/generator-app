<?php

namespace App\Filament\Resources\ReadingResource\Pages;

use App\Filament\Resources\ReadingResource;
use App\Models\Subscriber;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateReading extends CreateRecord
{
    protected static string $resource = ReadingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $subscriber = Subscriber::find($data['subscriber_id']);
        if (! $subscriber) {
            throw ValidationException::withMessages(['subscriber_id' => __('t.err_subscriber_missing')]);
        }
        if (! $subscriber->isMetered()) {
            throw ValidationException::withMessages(['subscriber_id' => __('t.err_reading_flat')]);
        }
        if ($subscriber->readingFor($data['period'])) {
            throw ValidationException::withMessages(['period' => __('t.text_err_reading_exists')]);
        }
        if (($data['reading_mode'] ?? ReadingResource::MODE_NORMAL) === ReadingResource::MODE_ESTIMATED
            && blank($data['estimate_reason'] ?? null)) {
            throw ValidationException::withMessages(['estimate_reason' => __('t.estimate_reason_required')]);
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $subscriber = Subscriber::findOrFail($data['subscriber_id']);

        try {
            $result = ReadingResource::record($subscriber, $data, auth()->id());
        } catch (\RuntimeException $e) {
            Notification::make()->title(__('t.err_reading_failed'))->body($e->getMessage())->danger()->send();
            throw ValidationException::withMessages(['period' => $e->getMessage()]);
        }

        Notification::make()
            ->title(__('t.reading_ok'))
            ->body(ReadingResource::recordSummary($result['reading'], (float) $result['bill']->total, (float) $result['bill']->prev_debt))
            ->success()
            ->send();

        return $result['reading'];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
