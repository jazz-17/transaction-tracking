<?php

namespace Database\Factories;

use App\Enums\TransactionKind;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'date' => fake()->dateTimeBetween('-1 year')->format('Y-m-d'),
            'payee' => fake()->company(),
            'memo' => null,
            'kind' => TransactionKind::Expense,
        ];
    }
}
