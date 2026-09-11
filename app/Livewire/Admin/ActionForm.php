<?php

namespace App\Livewire\Admin;

use App\Admin\ActionDefinition;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * Runs one ActionDefinition against one record in a Metronic modal.
 *
 * Visibility is driven by Livewire state rather than KTModal: the modal only exists in the
 * DOM while $open is true, so there is no initialisation timing to get wrong and no orphaned
 * backdrop when a request re-renders the list underneath it.
 */
class ActionForm extends Component
{
    /** @var class-string<ActionDefinition> */
    public string $action;

    public int $subjectId;

    public bool $open = false;

    /** @var array<string, mixed> */
    public array $state = [];

    /**
     * The page this modal was rendered on, so the post-action redirect can return to it.
     *
     * Passed in from the blade (`url()->fullUrl()` at render time) rather than read from the
     * request's Referer header. That header is client-supplied: a forged value was followed
     * verbatim (an open redirect), and an absent one — no-referrer policies, privacy
     * extensions — sent the operator to the Livewire update endpoint as a JSON page.
     */
    public string $returnTo = '';

    /**
     * Render the trigger as a bare icon button.
     *
     * A table row has room for an icon and a tooltip, not for a sentence; a profile header has room
     * for the sentence. Same modal either way, so only the trigger changes.
     */
    public bool $iconOnly = false;

    public function mount(string $action, int $subjectId, ?string $returnTo = null): void
    {
        if (! is_subclass_of($action, ActionDefinition::class)) {
            throw new \InvalidArgumentException("{$action} is not an action definition.");
        }

        $this->action = $action;
        $this->subjectId = $subjectId;
        $this->returnTo = $returnTo ?? '';
    }

    public function definition(): ActionDefinition
    {
        return app($this->action);
    }

    public function subject(): Model
    {
        $model = $this->definition()->subjectModel();

        return $model::query()->findOrFail($this->subjectId);
    }

    /**
     * Open the modal.
     *
     * NOT named `open()`: this component has a `public bool $open` for the view to read, and
     * Livewire's `$wire` proxy resolves a PROPERTY before a method of the same name — so
     * `wire:click="open"` compiled to `$wire.open()` against a boolean and threw
     * "not a function" inside the click handler. The click did nothing, no request was sent,
     * and nothing was logged. A test calling the method directly never sees it.
     */
    public function openModal(): void
    {
        $this->state = $this->definition()->defaults($this->subject());
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetErrorBag();
    }

    public function submit(): void
    {
        $subject = $this->subject();
        // forSubject() before validation: rules() needs the record to exclude it from a
        // uniqueness check.
        $definition = $this->definition()->forSubject($subject);

        $this->validate(
            $definition->rules($this->state, $subject),
            [],
            $definition->validationAttributes(),
        );

        try {
            $result = $definition->handle($this->state, $subject);
            session()->flash($result['ok'] ? 'status' : 'error', $result['message']);
        } catch (\RuntimeException $e) {
            // A refusal is a business rule, not a crash: show the service's own words and
            // keep the modal open so the operator can correct the input.
            $this->addError('action', $e->getMessage());

            return;
        }

        $this->open = false;

        // The record this action ran against is rendered by the PAGE, not by this component
        // (a subscriber's balance sits in a card beside it), so a partial refresh would leave
        // the operator reading the pre-action figure. Reload instead — at the URL the server
        // rendered this modal on, falling back to the session's last page if that is missing.
        $this->redirect($this->returnTo ?: url()->previous(), navigate: false);
    }

    public function shows(array $field): bool
    {
        return ! isset($field['visible_when']) || ($field['visible_when'])($this->state);
    }

    // ---- repeater lines (itemized special invoices) ---------------------------

    public function addItem(string $key): void
    {
        $lines = $this->state[$key] ?? [];
        $lines[] = ['description' => '', 'quantity' => 1, 'unit_price' => 0];
        $this->state[$key] = $lines;
    }

    public function removeItem(string $key, int $index): void
    {
        $lines = $this->state[$key] ?? [];
        unset($lines[$index]);
        // Re-index: the view binds state.<key>.<index>.*, so a hole in the array
        // renumbers on the next render and the bindings stop matching what is displayed.
        $this->state[$key] = array_values($lines);
    }

    public function render()
    {
        $subject = $this->subject();
        $definition = $this->definition()->forSubject($subject);

        return view('livewire.admin.action-form', [
            'definition' => $definition,
            'subject' => $subject,
            'sections' => $definition->sections(),
        ]);
    }
}
