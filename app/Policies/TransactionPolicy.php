<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

/**
 * Defense-in-depth (decision #7, layer 4): the global scope already hides other
 * users' transactions, but every mutating action is also explicitly authorized.
 */
class TransactionPolicy
{
    public function update(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->user_id;
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->user_id;
    }
}
