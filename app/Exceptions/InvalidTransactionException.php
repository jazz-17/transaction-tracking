<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by the RecordTransaction write path when a posting set would violate a
 * ledger invariant. Persisting never happens when this is thrown.
 */
final class InvalidTransactionException extends RuntimeException
{
    public static function tooFewPostings(int $count): self
    {
        return new self("A transaction needs at least 2 postings, got {$count}.");
    }

    public static function unbalanced(int $sum): self
    {
        return new self("Single-currency transaction postings must sum to zero; got {$sum}.");
    }

    /**
     * @param  array<int, string>  $currencies
     */
    public static function tooManyCurrencies(array $currencies): self
    {
        $list = implode(', ', $currencies);

        return new self("A transaction may touch at most two currencies, one being base; got [{$list}].");
    }

    /**
     * @param  array<int, string>  $currencies
     */
    public static function exchangeWithoutBase(array $currencies): self
    {
        $list = implode(', ', $currencies);

        return new self("A cross-currency exchange must include the base currency; got [{$list}].");
    }

    /**
     * @param  array<int, int>  $accountIds
     */
    public static function accountNotOwned(array $accountIds): self
    {
        $ids = implode(', ', $accountIds);

        return new self("Account(s) [{$ids}] do not belong to the user.");
    }

    public static function currencyMismatch(int $accountId, string $expected, string $actual): self
    {
        return new self("Posting on account [{$accountId}] must be denominated in {$expected}, got {$actual}.");
    }
}
