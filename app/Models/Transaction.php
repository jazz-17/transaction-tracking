<?php

namespace App\Models;

use App\Enums\TransactionKind;
use App\Exceptions\InvalidTransactionException;
use App\Models\Concerns\BelongsToUser;
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
     * Whether the persisted postings satisfy the ledger invariants
     * (>= 2 postings and Σ base_amount = 0). Reads fresh from the database.
     */
    public function isBalanced(): bool
    {
        $query = Posting::withoutGlobalScope('user')->where('transaction_id', $this->getKey());

        return $query->count() >= 2 && (int) $query->sum('base_amount') === 0;
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
        $query = Posting::withoutGlobalScope('user')->where('transaction_id', $this->getKey());

        $count = $query->count();
        if ($count < 2) {
            throw InvalidTransactionException::tooFewPostings($count);
        }

        $baseSum = (int) $query->sum('base_amount');
        if ($baseSum !== 0) {
            throw InvalidTransactionException::unbalanced($baseSum);
        }
    }
}
