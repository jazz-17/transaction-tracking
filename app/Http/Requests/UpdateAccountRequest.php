<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Type, currency, and `is_group` are fixed at creation (changing them on an account
 * with history would corrupt balances or the rollup), so only descriptive fields,
 * archived state, and the parent (re-homing a leaf) are editable.
 */
class UpdateAccountRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => $this->parentRules(),
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'archived' => ['boolean'],
        ];
    }

    /**
     * Re-homing constraints mirror creation (decision #13): a group stays a root, and a
     * leaf may move under any of the user's own root groups of the same type. The
     * `id != parent_id` guard is structurally redundant (a leaf can't be a group parent)
     * but kept as defense.
     *
     * @return array<int, ValidationRule|string>
     */
    private function parentRules(): array
    {
        /** @var Account $account */
        $account = $this->route('account');

        if ($account->isGroup()) {
            return ['nullable', 'prohibited'];
        }

        return [
            'nullable',
            'integer',
            Rule::exists('accounts', 'id')
                ->where('user_id', $this->user()->id)
                ->where('type', $account->type->value)
                // Integer 1, not boolean true: the Exists rule string-casts where values.
                ->where('is_group', 1)
                ->whereNull('parent_id')
                ->whereNot('id', $account->getKey()),
        ];
    }
}
