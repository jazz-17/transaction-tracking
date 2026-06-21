<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\TransactionKind;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validates the semantic quick-entry payload (one money account + a category, or two
 * money accounts for a transfer). The controller turns it into balanced PostingInputs;
 * RecordTransaction remains the sole write path and re-checks ownership.
 *
 * A cross-currency transfer's "destination amount" is required only when the two
 * accounts' currencies differ — that condition needs the accounts, so it's enforced
 * in {@see after()} (decision #16).
 */
class StoreTransactionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;
        $moneyTypes = [AccountType::Asset->value, AccountType::Liability->value];

        $rules = [
            'kind' => ['required', Rule::enum(TransactionKind::class)],
            'date' => ['required', 'date'],
            'payee' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'gt:0'],
            // Set by the UI to acknowledge a deviation-guard warning and record anyway (decision #11).
            'confirm_rate' => ['nullable', 'boolean'],
        ];

        if ($this->input('kind') === TransactionKind::Transfer->value) {
            $rules['from_account_id'] = ['required', 'integer', 'different:to_account_id', $this->ownedAccount($userId, $moneyTypes)];
            $rules['to_account_id'] = ['required', 'integer', $this->ownedAccount($userId, $moneyTypes)];
            $rules['to_amount'] = ['nullable', 'numeric', 'gt:0'];

            return $rules;
        }

        $categoryType = $this->input('kind') === TransactionKind::Income->value
            ? AccountType::Income->value
            : AccountType::Expense->value;

        $rules['account_id'] = ['required', 'integer', $this->ownedAccount($userId, $moneyTypes)];
        $rules['category_id'] = ['required', 'integer', $this->ownedAccount($userId, [$categoryType])];

        return $rules;
    }

    /**
     * Cross-field checks that depend on the selected accounts' currencies. A foreign
     * purchase needs no rate (decision #11); only a cross-currency transfer is an
     * exchange, and it must involve base with a destination amount (decision #16).
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $base = (string) $this->user()->base_currency;

                if ($this->input('kind') === TransactionKind::Transfer->value) {
                    $from = $this->account((int) $this->input('from_account_id'));
                    $to = $this->account((int) $this->input('to_account_id'));

                    if ($from->currency !== $to->currency) {
                        // A cross-currency transfer is an exchange; it must involve base.
                        if ($from->currency !== $base && $to->currency !== $base) {
                            $validator->errors()->add('to_account_id', "A cross-currency transfer must involve your base currency ({$base}).");
                        }

                        if (! $this->filled('to_amount')) {
                            $validator->errors()->add('to_amount', 'Enter the amount received in the destination currency.');
                        }
                    }

                    $this->assertScale($validator, 'amount', (string) $from->currency);
                    $this->assertScale($validator, 'to_amount', (string) $to->currency);

                    return;
                }

                $money = $this->account((int) $this->input('account_id'));
                $this->assertScale($validator, 'amount', (string) $money->currency);
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
     * The concrete Exists return type matters: typing this `string` (or a union with
     * it) would coerce the rule via __toString(), silently dropping the whereIn('type')
     * query callback and letting any account type through.
     *
     * @param  list<string>  $types
     */
    private function ownedAccount(int $userId, array $types): Exists
    {
        return Rule::exists('accounts', 'id')
            ->where('user_id', $userId)
            ->whereIn('type', $types)
            // Groups are non-postable headers (decision #13): excluding them here is the
            // server-side leaf-only guarantee, mirroring how equity is excluded by type.
            // Integer 0, not boolean false: the Exists rule string-casts where values, and
            // (string) false is '' — which matches no row — whereas '0' matches correctly.
            ->where('is_group', 0);
    }

    private function account(int $id): Account
    {
        // The global scope already restricts this to the current user; ownership of
        // every id was confirmed by the exists() rules above.
        return Account::query()->findOrFail($id);
    }
}
