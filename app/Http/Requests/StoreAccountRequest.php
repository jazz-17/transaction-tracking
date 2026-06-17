<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\ValidationRule;
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
                Rule::requiredIf(fn (): bool => in_array($this->input('type'), [
                    AccountType::Asset->value,
                    AccountType::Liability->value,
                ], true)),
                Rule::in(Currencies::codes()),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('user_id', $this->user()->id),
            ],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
        ];
    }
}
