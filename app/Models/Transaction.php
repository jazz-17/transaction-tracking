<?php

namespace App\Models;

use App\Enums\TransactionKind;
use App\Exceptions\InvalidTransactionException;
use App\Models\Concerns\BelongsToUser;
use App\Support\Ledger\TransactionBalance;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property string|null $payee
 * @property string|null $memo
 * @property TransactionKind $kind
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['date', 'payee', 'memo', 'kind'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'kind' => TransactionKind::class,
        ];
    }

    /**
     * @return HasMany<Posting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class);
    }

    /**
     * Whether the persisted postings satisfy the conservation invariant (decision #16).
     * Reads fresh from the database and runs the same check as the write path.
     */
    public function isBalanced(): bool
    {
        return TransactionBalance::isBalanced($this->baseCurrency(), $this->persistedLines());
    }

    /**
     * Model-level backstop: re-verifies the persisted posting set inside the write
     * transaction via a separate code path from the service's input validation, so a
     * stray write or arithmetic bug can never commit an unbalanced transaction.
     *
     * @throws InvalidTransactionException
     */
    public function assertBalanced(): void
    {
        TransactionBalance::assert($this->baseCurrency(), $this->persistedLines());
    }

    /**
     * The owner's base currency — the one currency a cross-currency swap must include (decision #9/#16).
     */
    private function baseCurrency(): string
    {
        return (string) $this->user->base_currency;
    }

    /**
     * The persisted postings as bare lines for {@see TransactionBalance}.
     *
     * @return array<int, array{amount: int, currency: string}>
     */
    private function persistedLines(): array
    {
        return Posting::withoutGlobalScope('user')
            ->where('transaction_id', $this->getKey())
            ->get(['amount', 'currency'])
            ->map(fn (Posting $posting): array => [
                'amount' => (int) $posting->amount,
                'currency' => $posting->currency,
            ])
            ->all();
    }
}
