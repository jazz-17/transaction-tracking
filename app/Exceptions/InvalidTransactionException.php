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

    public static function unbalanced(int $baseSum): self
    {
        return new self("Transaction postings must sum to zero in base currency; got {$baseSum}.");
    }

    /**
     * @param  array<int, int>  $accountIds
     */
    public static function accountNotOwned(array $accountIds): self
    {
        $ids = implode(', ', $accountIds);

        return new self("Account(s) [{$ids}] do not belong to the user.");
    }
}
