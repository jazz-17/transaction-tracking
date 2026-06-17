<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Users create My Accounts and Categories; equity is system-managed.
            'type' => ['required', Rule::in([
                AccountType::Asset->value,
                AccountType::Liability->value,
                AccountType::Income->value,
                AccountType::Expense->value,
            ])],
            // Required for asset/liability (their native currency); ignored for categories.
            'currency' => [
                'nullable',
                'string',
                Rule::requiredIf(fn (): bool => $this->isMyAccount()),
                Rule::in(Currencies::codes()),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('user_id', $this->user()->id),
            ],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            // Optional starting balance for a My Account, entered as the human-friendly
            // display value (what you have / what you owe); the controller seeds it
            // against the equity account via RecordTransaction.
            'opening_balance' => ['nullable', 'numeric', 'gt:0'],
            'opening_balance_base' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    /**
     * The base value of an FX opening balance can't be inferred without a rate
     * (decision #11), so require it when the account currency differs from base.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isMyAccount() || ! $this->filled('opening_balance')) {
                    return;
                }

                $base = (string) $this->user()->base_currency;

                if ($this->input('currency') !== $base && ! $this->filled('opening_balance_base')) {
                    $validator->errors()->add('opening_balance_base', "Enter the opening balance in {$base}.");
                }
            },
        ];
    }

    private function isMyAccount(): bool
    {
        return in_array($this->input('type'), [
            AccountType::Asset->value,
            AccountType::Liability->value,
        ], true);
    }
}
