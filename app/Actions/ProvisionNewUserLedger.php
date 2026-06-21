<?php

namespace App\Actions;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a brand-new user's ledger right after onboarding (decision #10): the hidden
 * equity "Opening Balances" account, a starter Cash asset, and a curated set of
 * income/expense categories — so the very first expense is recordable in seconds.
 *
 * Idempotent: it does nothing if the user already has accounts.
 */
final class ProvisionNewUserLedger
{
    private const EXPENSE_CATEGORIES = [
        'Groceries', 'Dining', 'Transport', 'Housing',
        'Utilities', 'Health', 'Entertainment', 'Shopping',
        // A home for bank/FX/ATM fees so an exchange's fee (and any foreign fee recorded as
        // its own single-currency expense) is bookable in seconds (decision #11).
        'Fees & Charges',
    ];

    private const INCOME_CATEGORIES = [
        'Salary', 'Gifts', 'Other Income',
    ];

    public function provision(User $user): void
    {
        if ($user->accounts()->withoutGlobalScope('user')->exists()) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $user->accounts()->create([
                'name' => 'Opening Balances',
                'type' => AccountType::Equity,
            ]);

            $user->accounts()->create([
                'name' => 'Cash',
                'type' => AccountType::Asset,
                'currency' => $user->base_currency,
            ]);

            foreach (self::EXPENSE_CATEGORIES as $name) {
                $user->accounts()->create(['name' => $name, 'type' => AccountType::Expense]);
            }

            foreach (self::INCOME_CATEGORIES as $name) {
                $user->accounts()->create(['name' => $name, 'type' => AccountType::Income]);
            }
        });
    }
}
