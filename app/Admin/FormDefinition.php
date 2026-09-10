<?php

namespace App\Admin;

use Illuminate\Database\Eloquent\Model;

/**
 * Description of one admin form.
 *
 * Rendered by App\Livewire\Admin\ResourceForm, which owns validation, the create/edit
 * distinction and the redirect. A screen supplies fields plus a save() that goes through
 * the domain service — never a raw model write, so billing rules stay in one place.
 *
 * Field shape:
 *   key          string   required
 *   label        string   required
 *   type         string   text|email|tel|number|money|select|textarea|date|password|checkbox
 *   rules        array    Laravel validation rules, e.g. ['required','max:150']
 *   options      array    value => label            (type=select)
 *   options_from Closure  fn(): array               (type=select, resolved per render)
 *   helper       string   small text under the field
 *   default      mixed    value for a new record
 *   visible_when Closure  fn(array $state): bool — hides the field AND drops its rules
 *   span         int      grid columns to span, 1-3, or 'full'
 *   months       bool     type=date with a month picker
 *   placeholder  string
 *   autocomplete string
 */
abstract class FormDefinition
{
    /** @return class-string<Model> */
    abstract public function model(): string;

    /**
     * Grouped fields: [['title' => …, 'description' => …, 'fields' => [...]]].
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function sections(): array;

    /** Persist. Returns the saved record so the caller can redirect to it. */
    abstract public function save(array $state, ?Model $record): Model;

    /** @return array<string, mixed> initial state for a new record */
    public function defaults(): array
    {
        $state = [];

        foreach ($this->fields() as $field) {
            $state[$field['key']] = $field['default'] ?? null;
        }

        return $state;
    }

    /** @return array<string, mixed> state read off an existing record */
    public function stateFrom(Model $record): array
    {
        $state = [];

        foreach ($this->fields() as $field) {
            $state[$field['key']] = data_get($record, $field['key']);
        }

        return $state;
    }

    /** Flat list of every field across all sections. */
    public function fields(): array
    {
        return collect($this->sections())
            ->flatMap(fn (array $section) => $section['fields'])
            ->values()
            ->all();
    }

    /**
     * Validation rules for the CURRENT state.
     *
     * A rule on a hidden field is a trap: the create form asks for a meter number only
     * when the type is "metered", so validating the rule for a flat subscriber would
     * refuse a form the operator cannot satisfy.
     *
     * @return array<string, mixed>
     */
    public function rules(array $state, ?Model $record = null): array
    {
        $rules = [];

        foreach ($this->fields() as $field) {
            if (isset($field['visible_when']) && ! ($field['visible_when'])($state)) {
                continue;
            }

            $fieldRules = $field['rules'] ?? [];

            // A field declared `unique` becomes a uniqueness rule that ignores the record
            // being edited — otherwise re-saving an unchanged form reports a clash with
            // itself.
            if (! empty($field['unique'])) {
                [$table, $column] = explode(',', $field['unique'], 2);
                $fieldRules[] = \Illuminate\Validation\Rule::unique($table, $column)->ignore($record?->getKey());
            }

            if ($fieldRules !== []) {
                $rules['state.'.$field['key']] = $fieldRules;
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return collect($this->fields())
            ->mapWithKeys(fn (array $field) => ['state.'.$field['key'] => $field['label']])
            ->all();
    }

    /** Where to go after a successful save. */
    public function redirectAfterSave(Model $record): string
    {
        return url()->current();
    }

    public function heading(?Model $record): string
    {
        return $record
            ? __('t.edit_record').' — '.$this->recordTitle($record)
            : __('t.new_record');
    }

    public function recordTitle(Model $record): string
    {
        return (string) ($record->name ?? $record->getKey());
    }
}
