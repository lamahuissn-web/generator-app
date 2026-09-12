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
 *   options_from Closure  fn(array $state): array   (type=select, resolved per render)
 *   helper       string   small text under the field
 *   default      mixed    value for a new record
 *   visible_when Closure  fn(array $state): bool — hides the field AND drops its rules
 *   required_when Closure fn(array $state): bool — field is required only in that state
 *   helper_from  Closure  fn(array $state): ?string — helper text computed from the state
 *   live         bool     re-render on every keystroke (a field that drives the others)
 *   span         int      grid columns to span, 1-3, or 'full'
 *   months       bool     type=date with a month picker
 *   placeholder  string
 *   autocomplete string
 */
abstract class FormDefinition
{
    /** Named constructor so callers never write `new X` and can chain off a string class name. */
    public static function make(): static
    {
        return new static;
    }

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

    /**
     * Initial state for a new record, or — for an action — for the record it runs against.
     *
     * $subject is why this signature is not empty: collecting a payment should arrive with
     * the subscriber's full balance already in the box, because that is the amount a
     * collector takes in the overwhelming majority of visits.
     *
     * @return array<string, mixed>
     */
    public function defaults(?Model $subject = null): array
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
     * The fields the operator is actually shown in this state — section visibility first,
     * then the field's own.
     *
     * Values for hidden fields stay in the state (so switching a subscriber's type back and
     * forth does not lose what was typed); what changes is which of them are ASKED for. This is
     * the one description of that rule: rules() validates against it and the form view renders
     * from it, so a field that is not on the screen is never required by it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleFields(array $state): array
    {
        return collect($this->sections())
            ->filter(fn (array $section): bool => $this->showsSection($section, $state))
            ->flatMap(fn (array $section): array => $section['fields'])
            ->filter(fn (array $field): bool => $this->showsField($field, $state))
            ->values()
            ->all();
    }

    /** Is this whole section on the screen for this state? */
    public function showsSection(array $section, array $state): bool
    {
        return ! isset($section['visible_when']) || ($section['visible_when'])($state);
    }

    /** Is this field on the screen for this state? */
    public function showsField(array $field, array $state): bool
    {
        return ! isset($field['visible_when']) || ($field['visible_when'])($state);
    }

    /**
     * Validation rules for the CURRENT state.
     *
     * A rule on a field the operator cannot see is a trap. The create form asks for a meter
     * number only when the type is "metered", so validating that rule for a flat subscriber
     * would refuse a form nobody can satisfy — and the same is true of a field inside a hidden
     * SECTION, which is how the subscriber form is built (one section for a metered house, one
     * for a flat one). That second case is not hypothetical: it refused every metered
     * subscriber, on create AND on edit, with "the monthly flat fee is required" attached to an
     * input that was never rendered — so pressing Save looked like pressing nothing at all.
     *
     * Both levels of visibility are therefore resolved by visibleFields() and read here, so the
     * rules and the screen cannot disagree about what the operator was asked for.
     *
     * @return array<string, mixed>
     */
    public function rules(array $state, ?Model $record = null): array
    {
        $rules = [];

        foreach ($this->visibleFields($state) as $field) {
            $fieldRules = $field['rules'] ?? [];

            // A rule that is conditional is written as required_when rather than a hand-rolled
            // closure in each definition: the field is required only in the state where it is
            // shown, so switching mode cannot leave a rule nobody can satisfy.
            if (isset($field['required_when'])) {
                // The declared rules must not also say required/nullable: which of the two wins
                // is a validator implementation detail, so the conditional replaces them.
                $fieldRules = array_values(array_diff($fieldRules, ['required', 'nullable']));
                $fieldRules[] = ($field['required_when'])($state) ? 'required' : 'nullable';
            }

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
