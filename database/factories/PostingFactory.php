<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Posting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Posting>
 *
 * Real postings are written by the RecordTransaction service in balanced sets;
 * this factory exists for low-level model tests and rarely produces a balanced pair.
 */
class PostingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->numberBetween(-100000, 100000);

        return [
            'transaction_id' => Transaction::factory(),
            'account_id' => Account::factory(),
            'user_id' => User::factory(),
            'amount' => $amount,
            'currency' => 'PEN',
            'base_amount' => $amount,
            'memo' => null,
        ];
    }
}
