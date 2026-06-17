<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => AccountType::Expense,
            'currency' => null,
            'parent_id' => null,
            'archived' => false,
        ];
    }

    public function asset(string $currency = 'PEN'): static
    {
        return $this->state(fn () => ['type' => AccountType::Asset, 'currency' => $currency]);
    }

    public function liability(string $currency = 'PEN'): static
    {
        return $this->state(fn () => ['type' => AccountType::Liability, 'currency' => $currency]);
    }

    public function income(): static
    {
        return $this->state(fn () => ['type' => AccountType::Income, 'currency' => null]);
    }

    public function expense(): static
    {
        return $this->state(fn () => ['type' => AccountType::Expense, 'currency' => null]);
    }

    public function equity(): static
    {
        return $this->state(fn () => ['type' => AccountType::Equity, 'currency' => null]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived' => true]);
    }
}
