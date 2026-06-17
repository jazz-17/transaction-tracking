<?php

namespace App\Policies;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;

/**
 * Defense-in-depth (decision #7, layer 4): the global scope already hides other
 * users' accounts, but every mutating action is also explicitly authorized.
 */
class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return $user->id === $account->user_id;
    }

    public function update(User $user, Account $account): bool
    {
        return $user->id === $account->user_id;
    }

    /**
     * The hidden equity "Opening Balances" account underpins every seeded balance and is
     * never user-facing (decision #10); deleting it would unbalance history, so it can
     * never be deleted by anyone.
     */
    public function delete(User $user, Account $account): bool
    {
        return $user->id === $account->user_id && $account->type !== AccountType::Equity;
    }
}
