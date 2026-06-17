<?php

namespace App\Policies;

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

    public function delete(User $user, Account $account): bool
    {
        return $user->id === $account->user_id;
    }
}
