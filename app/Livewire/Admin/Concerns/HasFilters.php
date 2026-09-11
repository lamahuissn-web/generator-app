<?php

namespace App\Livewire\Admin\Concerns;

/**
 * A filter bar for the read-only screens: reports, the billing run, the ledger.
 *
 * Filters are ONE array property keyed by field, described by the same schema a form uses
 * (see App\Admin\FormDefinition). That is why the filter bar, the edit form and the action
 * modal all render through <x-admin.field> — three screens, one definition shape, one
 * place where a new control type has to be taught.
 *
 * No address-bar sync: the screens that need shareable URLs (the collector ledger) receive
 * their filters as query params on mount and carry them in the print/export links, which is
 * what the operator actually shares.
 */
trait HasFilters
{
    /** @var array<string, mixed> field key => value */
    public array $filters = [];

    /**
     * The filter fields, in the schema shape the form and the modal also use.
     *
     * Public because the VIEW renders the bar from it — the alternative is a second copy of
     * the field list inside the Blade file, which is exactly the drift this trait exists to
     * prevent.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function filterSchema(): array;

    public function mountFilters(): void
    {
        $this->filters = $this->defaultFilters();
    }

    /** @return array<string, mixed> */
    protected function defaultFilters(): array
    {
        $defaults = [];

        foreach ($this->filterSchema() as $field) {
            $defaults[$field['key']] = $field['default'] ?? null;
        }

        return $defaults;
    }

    /**
     * Back to the defaults.
     *
     * Also clears validation: a reset that leaves a red error under a now-empty field is
     * indistinguishable from a filter that is still broken.
     */
    public function resetFilters(): void
    {
        $this->filters = $this->defaultFilters();
        $this->resetErrorBag();
    }

    /** A raw filter value, or null when it is unset/blank. */
    protected function filter(string $key): mixed
    {
        $value = $this->filters[$key] ?? null;

        return $value === '' ? null : $value;
    }

    public function updatedFilters(): void
    {
        $this->validateFilters();
    }

    /**
     * Validates whatever the schema declares.
     *
     * Reports call this on every change rather than only on submit: there IS no submit — the
     * page re-renders on each change, so an invalid value would reach the query immediately.
     */
    public function validateFilters(): array
    {
        $rules = [];

        foreach ($this->filterSchema() as $field) {
            if (! empty($field['rules'])) {
                $rules['filters.'.$field['key']] = $field['rules'];
            }
        }

        return $rules === [] ? [] : $this->validate($rules);
    }
}
