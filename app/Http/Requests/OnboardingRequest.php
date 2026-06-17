<?php

namespace App\Http\Requests;

use App\Support\Currencies;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnboardingRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'base_currency' => ['required', 'string', Rule::in(Currencies::codes())],
        ];
    }
}
