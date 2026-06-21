<?php

namespace App\Support\Ledger;

use App\Exceptions\InvalidTransactionException;

/**
 * The conservation invariant (decision #16), evaluated on a bare list of ledger lines.
 *
 * Both the write path (`RecordTransaction`, on `PostingInput`s) and the model backstop
 * (`Transaction::assertBalanced`, on persisted `Posting`s) reduce to the same check, so
 * it lives here once. A transaction is valid when it is either:
 *
 *   - **single-currency** — every leg shares one currency and `Σ amount = 0` (exact); or
 *   - a **two-currency swap including base** — exactly two currencies, one of which is the
 *     user's base. A cross-currency exchange is a swap of two observed amounts, not a
 *     conservation event, so it is validated **structurally only** — there is no weighted
 *     sum and no stored rate. Each account still conserves in its own currency on its own
 *     books; the implied rate, when needed, is derived on read as `−B/F` (decision #11).
 *
 * Three-or-more currencies, and foreign↔foreign swaps, are rejected.
 */
final class TransactionBalance
{
    /**
     * @param  array<int, array{amount: int, currency: string}>  $lines
     *
     * @throws InvalidTransactionException
     */
    public static function assert(string $baseCurrency, array $lines): void
    {
        $base = strtoupper($baseCurrency);

        $count = count($lines);
        if ($count < 2) {
            throw InvalidTransactionException::tooFewPostings($count);
        }

        $currencies = array_values(array_unique(array_map(
            fn (array $line): string => strtoupper($line['currency']),
            $lines,
        )));

        if (count($currencies) === 1) {
            self::assertSingleCurrency($lines);

            return;
        }

        if (count($currencies) > 2) {
            throw InvalidTransactionException::tooManyCurrencies($currencies);
        }

        if (! in_array($base, $currencies, true)) {
            throw InvalidTransactionException::exchangeWithoutBase($currencies);
        }

        // Two currencies, one is base: a valid swap. Nothing numeric to verify — the two
        // amounts are observed facts (decision #11/#16).
    }

    /**
     * @param  array<int, array{amount: int, currency: string}>  $lines
     */
    public static function isBalanced(string $baseCurrency, array $lines): bool
    {
        try {
            self::assert($baseCurrency, $lines);

            return true;
        } catch (InvalidTransactionException) {
            return false;
        }
    }

    /**
     * Single-currency: the native amounts conserve exactly (`Σ amount = 0`).
     *
     * @param  array<int, array{amount: int, currency: string}>  $lines
     */
    private static function assertSingleCurrency(array $lines): void
    {
        $sum = 0;

        foreach ($lines as $line) {
            $sum += $line['amount'];
        }

        if ($sum !== 0) {
            throw InvalidTransactionException::unbalanced($sum);
        }
    }
}
