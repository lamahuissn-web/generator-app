<?php

namespace App\Admin\Forms;

use App\Admin\FormDefinition;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Services\ReadingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Recording a meter reading.
 *
 * Create-only, and deliberately so: a reading is a measurement of a moment. Correcting one
 * after the fact means deleting it (which also removes the bill it produced) and recording it
 * again, so an edit screen would invite an edit that quietly left the old bill in place.
 *
 * The three modes and their fields come from ReadingService, which the subscriber-profile
 * action also uses — one definition of what "a reading" is, two doors to it.
 */
class ReadingForm extends FormDefinition
{
    public function model(): string
    {
        return Reading::class;
    }

    public function sections(): array
    {
        return [[
            'title' => __('mdl.reading'),
            'description' => __('t.reading_form_desc'),
            'fields' => ReadingService::modeFields(),
        ]];
    }

    public function defaults(?Model $subject = null): array
    {
        return array_merge(parent::defaults($subject), [
            'subscriber_search' => null,
            'subscriber_id' => $subject?->getKey(),
        ]);
    }

    public function save(array $state, ?Model $record): Model
    {
        $subscriber = Subscriber::findOrFail((int) $state['subscriber_id']);

        return ReadingService::record($subscriber, $state, auth()->id())['reading'];
    }

    public function redirectAfterSave(Model $record): string
    {
        // Back to the list, filtered to the period just written: entering a route's readings
        // one after another is the normal way this screen is used.
        return route('admin.readings.index');
    }

    public function recordTitle(Model $record): string
    {
        return (string) ($record->subscriber?->name ?? $record->getKey());
    }
}
