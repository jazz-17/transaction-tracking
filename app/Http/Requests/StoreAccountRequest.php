<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Support\Currencies;
use App\Support\Money;
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
            // Groups are non-postable headers (decision #13); leaves are postable accounts.
            'is_group' => ['boolean'],
            // Required for asset/liability (their native currency); ignored for categories
            // and for groups (a header has no money of its own).
            'currency' => [
                'nullable',
                'string',
                Rule::requiredIf(fn (): bool => $this->isMyAccount() && ! $this->isGroup()),
                Rule::in(Currencies::codes()),
            ],
            // A group is always a root, so it may not carry a parent. A leaf may nest under
            // a same-type root group (2 levels, enforced on write; read/rollup is depth-
            // agnostic so relaxing the cap later needs no migration).
            'parent_id' => $this->parentRules(),
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            // Optional starting balance for a My Account, entered as the human-friendly
            // display value (what you have / what you owe); the controller seeds it
            // against the equity account via RecordTransaction. Zero is allowed and
            // simply means "no starting balance" — same as leaving it blank.
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * An opening balance is entered in the account's own currency (decision #14) — no base
     * value, no rate. Just guard its scale against that currency's exponent.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isMyAccount() || ! $this->hasOpeningBalance()) {
                    return;
                }

                $this->assertScale($validator, 'opening_balance', (string) $this->input('currency'));
            },
        ];
    }

    /**
     * Reject an amount with more fractional digits than its currency supports, rather than
     * letting Money::parse refuse it later (USD≤2, JPY=0, KWD≤3).
     */
    private function assertScale(Validator $validator, string $field, string $currency): void
    {
        if (! $this->filled($field) || Money::isValidScale((string) $this->input($field), $currency)) {
            return;
        }

        $max = Money::fractionDigits($currency);
        $validator->errors()->add($field, "This amount may have at most {$max} decimal place(s) for {$currency}.");
    }

    /**
     * Constrain where a new account may sit in the hierarchy (decision #13).
     *
     * Groups are roots, so they may not carry a parent at all. A leaf's parent (when
     * given) must be one of the user's own **root groups of the same type** — this is
     * what enforces the 2-level cap and the same-`type` rule on every write.
     *
     * @return array<int, ValidationRule|string>
     */
    private function parentRules(): array
    {
        if ($this->isGroup()) {
            return ['nullable', 'prohibited'];
        }

        return [
            'nullable',
            'integer',
            Rule::exists('accounts', 'id')
                ->where('user_id', $this->user()->id)
                ->where('type', $this->input('type'))
                // Integer 1, not boolean true: the Exists rule string-casts where values.
                ->where('is_group', 1)
                ->whereNull('parent_id'),
        ];
    }

    private function isGroup(): bool
    {
        return $this->boolean('is_group');
    }

    private function isMyAccount(): bool
    {
        return in_array($this->input('type'), [
            AccountType::Asset->value,
            AccountType::Liability->value,
        ], true);
    }

    /**
     * Whether a non-zero opening balance was actually provided (a blank field or a 0
     * both mean "no starting balance", so neither needs an FX base value).
     */
    private function hasOpeningBalance(): bool
    {
        return $this->filled('opening_balance') && (float) $this->input('opening_balance') > 0;
    }
}
