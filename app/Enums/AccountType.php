<?php

namespace App\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Income = 'income';
    case Expense = 'expense';
    case Equity = 'equity';

    /**
     * Display sign applied only by the presentation layer.
     *
     * +1 = show the raw signed balance; -1 = negate so the number reads positive
     * for the human (a liability you owe, income you earned, equity you seeded).
     * Ledger math never uses this — it always sums raw amounts.
     */
    public function displaySign(): int
    {
        return match ($this) {
            self::Asset, self::Expense => 1,
            self::Liability, self::Income, self::Equity => -1,
        };
    }

    /**
     * "My Accounts" — real cards/wallets the user owns or owes. Carry a native currency.
     */
    public function isMyAccount(): bool
    {
        return $this === self::Asset || $this === self::Liability;
    }

    /**
     * "Categories" — income/expense buckets for reporting. Denominated in base currency.
     */
    public function isCategory(): bool
    {
        return $this === self::Income || $this === self::Expense;
    }

    /**
     * Whether the account stores its own native currency (asset/liability only)
     * rather than always using the user's base currency.
     *
     * Under Model A, income/expense categories AND the hidden equity "Opening
     * Balances" account all operate in base currency — only the cards/wallets the
     * user actually owns or owes carry a foreign native currency. So `currency` is
     * non-null exactly when this is true.
     */
    public function usesNativeCurrency(): bool
    {
        return $this->isMyAccount();
    }
}
