<?php

namespace App\Actions\Transactions;

/**
 * One balanced ledger line handed to RecordTransaction.
 *
 * Amounts are signed integer minor units in the posting's own `currency` — never
 * pre-translated to base, and no rate is carried (decision #4/#11). A cross-currency
 * transaction is just two lines in two currencies; its rate is derived on read.
 */
final readonly class PostingInput
{
    public function __construct(
        public int $accountId,
        public int $amount,
        public string $currency,
        public ?string $memo = null,
    ) {}
}
