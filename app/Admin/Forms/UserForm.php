<?php

namespace App\Admin\Forms;

use App\Admin\FormDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * A panel or portal account.
 *
 * The password is optional on EDIT — leaving it blank keeps the current one — so the rule is
 * computed per state rather than declared on the field.
 */
class UserForm extends FormDefinition
{
    public function model(): string
    {
        return User::class;
    }

    public function sections(): array
    {
        return [[
            'title' => __('mdl.user'),
            'fields' => [
                [
                    'key' => 'name',
                    'label' => __('t.full_name'),
                    'rules' => ['required', 'string', 'max:255'],
                ],
                [
                    'key' => 'email',
                    'label' => __('t.email'),
                    'type' => 'email',
                    'unique' => 'users,email',
                    'rules' => ['required', 'email', 'max:255'],
                ],
                [
                    'key' => 'role',
                    'label' => __('t.role'),
                    'type' => 'select',
                    'default' => User::ROLE_COLLECTOR,
                    'options' => [
                        User::ROLE_ADMIN => __('t.role_admin'),
                        User::ROLE_COLLECTOR => __('t.role_collector'),
                    ],
                    'helper' => __('t.role_hint'),
                    'rules' => ['required', 'in:'.User::ROLE_ADMIN.','.User::ROLE_COLLECTOR],
                ],
                [
                    'key' => 'status',
                    'label' => __('t.status'),
                    'type' => 'select',
                    'default' => User::STATUS_ACTIVE,
                    'options' => [
                        User::STATUS_ACTIVE => __('t.account_active'),
                        User::STATUS_INACTIVE => __('t.account_inactive'),
                    ],
                    'helper' => __('t.account_status_hint'),
                    'rules' => ['required', 'in:'.User::STATUS_ACTIVE.','.User::STATUS_INACTIVE],
                ],
                [
                    'key' => 'phone',
                    'label' => __('t.phone'),
                    'type' => 'tel',
                    'rules' => ['nullable', 'string', 'max:30'],
                ],
                [
                    'key' => 'password',
                    'label' => __('t.password'),
                    'type' => 'password',
                    'span' => 'full',
                    'helper' => __('s.pw_hint'),
                ],
            ],
        ]];
    }

    /** A password is required to create an account, optional to edit one. */
    public function rules(array $state, ?Model $record = null): array
    {
        $rules = parent::rules($state, $record);

        $rules['state.password'] = $record === null
            ? ['required', 'string', 'min:8']
            : ['nullable', 'string', 'min:8'];

        return $rules;
    }

    public function stateFrom(Model $record): array
    {
        $state = parent::stateFrom($record);

        // Never round-trip a hash into a password field.
        $state['password'] = null;

        return $state;
    }

    public function save(array $state, ?Model $record): Model
    {
        $attributes = [
            'name' => $state['name'],
            'email' => $state['email'],
            'role' => $state['role'],
            'status' => $state['status'] ?? User::STATUS_ACTIVE,
            'phone' => $state['phone'] ?: null,
        ];

        if (filled($state['password'] ?? null)) {
            $attributes['password'] = Hash::make((string) $state['password']);
        }

        if ($record === null) {
            return User::create($attributes);
        }

        $record->fill($attributes)->save();

        return $record;
    }

    public function redirectAfterSave(Model $record): string
    {
        return route('admin.users.index');
    }

    public function recordTitle(Model $record): string
    {
        return (string) $record->name;
    }
}
