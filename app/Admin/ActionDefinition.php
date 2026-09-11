<?php

namespace App\Admin;

use Illuminate\Database\Eloquent\Model;

/**
 * A modal action on one record: collect a payment, raise a special invoice, convert a
 * meter. Rendered by App\Livewire\Admin\ActionForm.
 *
 * Same field shape as FormDefinition (see there), plus:
 *   handle(array $state, Model $subject): array{ok: bool, message: string}
 *
 * Actions go through the domain services rather than writing models, so a correction made
 * here behaves exactly like the same correction made from a list row.
 */
abstract class ActionDefinition extends FormDefinition
{
    /** The record the action runs against. Kept separate from FormDefinition's save(). */
    abstract public function handle(array $state, Model $subject): array;

    /**
     * The record being acted on, bound by the component before validation.
     *
     * A rule such as "this meter number must be unique" has to exclude the record being
     * edited, and rules() receives only the state — so the subject rides on the definition.
     */
    public ?Model $subject = null;

    public function forSubject(Model $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /** Trigger button text. Defaults to the action's own name for the screen. */
    public function label(): string
    {
        return $this->modalHeading(app($this->subjectModel()));
    }

    /** KeenIcon name on the trigger button. */
    public function icon(): ?string
    {
        return null;
    }

    /** Modal heading, usually naming the record. */
    public function modalHeading(Model $subject): string
    {
        return $this->heading($subject);
    }

    public function modalDescription(): ?string
    {
        return null;
    }

    public function submitLabel(): string
    {
        return __('t.confirm');
    }

    public function submitVariant(): string
    {
        return 'primary';
    }

    /** May this action run for this record? A settled account cannot be collected from. */
    public function visible(Model $subject): bool
    {
        return true;
    }

    /** Extra classes on the triggering button, e.g. 'kt-btn-outline'. */
    public function triggerClass(): string
    {
        return '';
    }

    /** FormDefinition requires these; an action has none of them. */
    public function model(): string
    {
        return $this->subjectModel();
    }

    /** @return class-string<Model> */
    abstract public function subjectModel(): string;

    public function save(array $state, ?Model $record): Model
    {
        throw new \RuntimeException('Actions run through handle(), not save().');
    }
}
