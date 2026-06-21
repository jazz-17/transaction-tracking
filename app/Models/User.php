<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccountType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $base_currency
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Net worth as per-currency buckets (decision #15): Σ amount over asset + liability
     * accounts, grouped by currency — e.g. `['PEN' => 95000, 'USD' => -30000]`. Assets
     * carry value positive, liabilities negative, so a per-currency sum is net worth in
     * that currency. Not blended into one base total — that needs revaluation (deferred).
     *
     * @return array<string, int>
     */
    public function netWorth(): array
    {
        return DB::table('postings')
            ->join('accounts', 'accounts.id', '=', 'postings.account_id')
            ->where('postings.user_id', $this->getKey())
            ->whereIn('accounts.type', [AccountType::Asset->value, AccountType::Liability->value])
            ->groupBy('postings.currency')
            ->selectRaw('postings.currency as currency, SUM(postings.amount) as total')
            ->pluck('total', 'currency')
            ->map(fn (int|string $total): int => (int) $total)
            ->all();
    }
}
