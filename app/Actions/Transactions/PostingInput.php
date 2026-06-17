<?php

namespace App\Actions\Transactions;

/**
 * One balanced ledger line handed to RecordTransaction.
 *
 * Amounts are signed integer minor units. `baseAmount` is this line's value in the
 * user's base currency; the caller is responsible for that translation (for a
 * same-currency posting it simply equals `amount`).
 */
final readonly class PostingInput
{
    public function __construct(
        public int $accountId,
        public int $amount,
        public string $currency,
        public int $baseAmount,
        public ?string $memo = null,
    ) {}
}
