<?php

namespace App\Livewire\Admin;

use App\Admin\FormDefinition;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One form component for the whole panel, driven by a FormDefinition.
 *
 * Owns the create/edit distinction, validation against the CURRENT state (so a rule on a
 * field the operator cannot see never blocks them), the unsaved-changes guard, and the
 * redirect. Screens supply fields plus a save() that goes through the domain service.
 */
class ResourceForm extends Component
{
    // A receipt is photographed on site and uploaded through the form, so this component
    // needs Livewire's temporary-upload handling (the '' case is a stored path).
    use WithFileUploads;

    /** @var class-string<FormDefinition> */
    public string $form;

    public ?int $record = null;

    /** @var array<string, mixed> fieldKey => value */
    public array $state = [];

    public function mount(string $form, ?int $record = null): void
    {
        if (! is_subclass_of($form, FormDefinition::class)) {
            throw new \InvalidArgumentException("{$form} is not a form definition.");
        }

        $this->form = $form;
        $this->record = $record;

        $definition = $this->definition();

        $this->state = $record
            ? $definition->stateFrom($this->recordModel())
            : $definition->defaults();
    }

    public function definition(): FormDefinition
    {
        return app($this->form);
    }

    public function recordModel(): ?Model
    {
        if ($this->record === null) {
            return null;
        }

        $model = $this->definition()->model();

        return $model::query()->findOrFail($this->record);
    }

    public function save()
    {
        $definition = $this->definition();
        $record = $this->recordModel();

        $this->resetErrorBag();

        $this->validate(
            $definition->rules($this->state, $record),
            [],
            $definition->validationAttributes(),
        );

        try {
            $saved = $definition->save($this->state, $record);
        } catch (\RuntimeException $e) {
            // A refusal is a business rule, not a crash. The domain services refuse things a
            // rule cannot express — "a reading for this period already exists", "this subscriber
            // has no outstanding balance to collect" — and those messages are the operator's
            // instructions. Uncaught, the screen answered 500 and the operator saw nothing at
            // all (the action modal has always caught this; the screen did not). Keep the form
            // filled in and show the reason.
            $this->addError('save', $e->getMessage());

            return null;
        }

        session()->flash('status', $record ? __('t.updated_ok') : __('t.created_ok'));

        return $this->redirect($definition->redirectAfterSave($saved), navigate: false);
    }

    /** Breadcrumb/heading context for the view. */
    public function title(): string
    {
        return $this->definition()->heading($this->recordModel());
    }

    /** Is a field visible for the current state? Hidden fields are not rendered at all. */
    public function shows(array $field): bool
    {
        return ! isset($field['visible_when']) || ($field['visible_when'])($this->state);
    }

    /** Re-evaluate conditional fields without a round trip for every keystroke. */
    public function render()
    {
        return view('livewire.admin.resource-form', [
            'definition' => $this->definition(),
            'sections' => $this->definition()->sections(),
        ]);
    }
}
