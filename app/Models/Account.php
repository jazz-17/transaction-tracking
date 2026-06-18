<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property AccountType $type
 * @property string|null $currency
 * @property int|null $parent_id
 * @property bool $is_group
 * @property string|null $icon
 * @property string|null $color
 * @property bool $archived
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'currency', 'parent_id', 'is_group', 'icon', 'color', 'archived'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_group' => 'boolean',
            'archived' => 'boolean',
        ];
    }

    /**
     * A group is a non-postable header (decision #13): it carries no postings of its
     * own and its balance is the rolled-up sum of its children.
     */
    public function isGroup(): bool
    {
        return $this->is_group;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Posting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class);
    }

    /**
     * Native balance: Σ amount in this account's own currency (decision #5).
     * Computed from postings — the only source of truth, so it can't drift.
     */
    public function balance(): int
    {
        return (int) $this->postings()->sum('amount');
    }

    /**
     * Balance translated to the user's base currency: Σ base_amount.
     */
    public function baseBalance(): int
    {
        return (int) $this->postings()->sum('base_amount');
    }
}
