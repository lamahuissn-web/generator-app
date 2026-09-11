<?php

namespace App\Admin\Forms;

use App\Admin\FormDefinition;
use App\Models\Route;
use Illuminate\Database\Eloquent\Model;

class RouteForm extends FormDefinition
{
    public function model(): string
    {
        return Route::class;
    }

    public function sections(): array
    {
        return [[
            'title' => __('mdl.route'),
            'fields' => [
                [
                    'key' => 'name',
                    'label' => __('t.route_name'),
                    'span' => 'full',
                    'rules' => ['required', 'string', 'max:120'],
                ],
                [
                    'key' => 'notes',
                    'label' => __('s.notes'),
                    'type' => 'textarea',
                    'span' => 'full',
                    'rules' => ['nullable', 'string', 'max:500'],
                ],
            ],
        ]];
    }

    public function save(array $state, ?Model $record): Model
    {
        $attributes = [
            'name' => $state['name'],
            'notes' => $state['notes'] ?: null,
        ];

        if ($record === null) {
            return Route::create($attributes);
        }

        $record->fill($attributes)->save();

        return $record;
    }

    public function redirectAfterSave(Model $record): string
    {
        return route('admin.routes.index');
    }

    public function recordTitle(Model $record): string
    {
        return (string) $record->name;
    }
}
