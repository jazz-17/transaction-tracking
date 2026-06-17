<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Scopes a model to the authenticated user and stamps `user_id` on create.
 *
 * Layer 1 of per-user isolation: a global scope so reads can't forget to filter,
 * and a `creating` hook so writes can't forget to set the owner. The remaining
 * layers (explicit ownership checks of client-supplied ids, policies) live in the
 * service and controllers.
 */
trait BelongsToUser
{
    public static function bootBelongsToUser(): void
    {
        static::addGlobalScope('user', function (Builder $builder): void {
            if (Auth::check()) {
                $builder->where($builder->getModel()->qualifyColumn('user_id'), Auth::id());
            }
        });

        static::creating(function (self $model): void {
            if ($model->getAttribute('user_id') === null && Auth::check()) {
                $model->setAttribute('user_id', Auth::id());
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
