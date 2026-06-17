<?php

namespace App\Actions\Transactions;

use App\Enums\TransactionKind;
use App\Exceptions\InvalidTransactionException;
use App\Models\Account;
use App\Models\Posting;
use App\Models\Transaction;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for the ledger.
 *
 * Every transaction — quick entry, splits, transfers, opening balances, future
 * imports — funnels through here. It verifies ownership of every referenced
 * account, enforces the balancing invariants, and persists the header plus its
 * postings atomically. A model-level backstop re-checks the persisted state inside
 * the same database transaction (belt and suspenders).
 */
final class RecordTransaction
{
    /**
     * @param  array<int, PostingInput>  $postings
     */
    public function create(
        User $user,
        TransactionKind $kind,
        DateTimeInterface|string $date,
        array $postings,
        ?string $payee = null,
        ?string $memo = null,
    ): Transaction {
        $this->validate((int) $user->getKey(), $postings);

        return DB::transaction(function () use ($user, $kind, $date, $postings, $payee, $memo): Transaction {
            $transaction = new Transaction;
            $transaction->user_id = (int) $user->getKey();
            $transaction->kind = $kind;
            $transaction->date = Carbon::parse($date);
            $transaction->payee = $payee;
            $transaction->memo = $memo;
            $transaction->save();

            $this->writePostings($transaction, (int) $user->getKey(), $postings);
            $transaction->assertBalanced();

            return $transaction;
        });
    }

    /**
     * Edit a transaction by atomically replacing its entire posting set (decision #8).
     *
     * @param  array<int, PostingInput>  $postings
     */
    public function update(
        Transaction $transaction,
        TransactionKind $kind,
        DateTimeInterface|string $date,
        array $postings,
        ?string $payee = null,
        ?string $memo = null,
    ): Transaction {
        $userId = $transaction->user_id;
        $this->validate($userId, $postings);

        return DB::transaction(function () use ($transaction, $userId, $kind, $date, $postings, $payee, $memo): Transaction {
            $transaction->kind = $kind;
            $transaction->date = Carbon::parse($date);
            $transaction->payee = $payee;
            $transaction->memo = $memo;
            $transaction->save();

            Posting::withoutGlobalScope('user')
                ->where('transaction_id', $transaction->getKey())
                ->delete();

            $this->writePostings($transaction, $userId, $postings);
            $transaction->assertBalanced();

            return $transaction;
        });
    }

    /**
     * Hard delete a transaction and its postings (decision #8). Because balances are
     * computed from postings, their effect simply disappears — no drift possible.
     */
    public function delete(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            Posting::withoutGlobalScope('user')
                ->where('transaction_id', $transaction->getKey())
                ->delete();

            $transaction->delete();
        });
    }

    /**
     * @param  array<int, PostingInput>  $postings
     */
    private function validate(int $userId, array $postings): void
    {
        if (count($postings) < 2) {
            throw InvalidTransactionException::tooFewPostings(count($postings));
        }

        $accountIds = array_values(array_unique(array_map(
            fn (PostingInput $posting): int => $posting->accountId,
            $postings,
        )));

        $ownedIds = Account::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereIn('id', $accountIds)
            ->pluck('id')
            ->all();

        $missing = array_values(array_diff($accountIds, $ownedIds));
        if ($missing !== []) {
            throw InvalidTransactionException::accountNotOwned($missing);
        }

        $baseSum = array_sum(array_map(
            fn (PostingInput $posting): int => $posting->baseAmount,
            $postings,
        ));

        if ($baseSum !== 0) {
            throw InvalidTransactionException::unbalanced($baseSum);
        }
    }

    /**
     * @param  array<int, PostingInput>  $postings
     */
    private function writePostings(Transaction $transaction, int $userId, array $postings): void
    {
        foreach ($postings as $input) {
            $posting = new Posting;
            $posting->transaction_id = (int) $transaction->getKey();
            $posting->account_id = $input->accountId;
            $posting->user_id = $userId;
            $posting->amount = $input->amount;
            $posting->currency = strtoupper($input->currency);
            $posting->base_amount = $input->baseAmount;
            $posting->memo = $input->memo;
            $posting->save();
        }
    }
}
