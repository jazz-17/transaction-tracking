<?php

use App\Actions\Transactions\PostingInput;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\TransactionKind;
use App\Exceptions\InvalidTransactionException;
use App\Models\Account;
use App\Models\Posting;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['base_currency' => 'PEN']);
    $this->actingAs($this->user);

    $this->checking = Account::factory()->asset('PEN')->for($this->user)->create(['name' => 'Checking']);
    $this->visa = Account::factory()->liability('PEN')->for($this->user)->create(['name' => 'Visa']);
    $this->amex = Account::factory()->liability('USD')->for($this->user)->create(['name' => 'Amex']);
    $this->groceries = Account::factory()->expense()->for($this->user)->create(['name' => 'Groceries']);
    $this->dining = Account::factory()->expense()->for($this->user)->create(['name' => 'Dining']);
    $this->opening = Account::factory()->equity()->for($this->user)->create(['name' => 'Opening Balances']);

    $this->record = app(RecordTransaction::class);
});

it('records a balanced single-currency expense', function () {
    $txn = $this->record->create(
        $this->user,
        TransactionKind::Expense,
        '2026-06-17',
        [
            new PostingInput($this->visa->id, -5000, 'PEN', -5000),
            new PostingInput($this->groceries->id, 5000, 'PEN', 5000),
        ],
        payee: 'Wong',
    );

    expect($txn->postings()->count())->toBe(2)
        ->and($txn->payee)->toBe('Wong')
        ->and($txn->isBalanced())->toBeTrue()
        ->and($this->visa->balance())->toBe(-5000)
        ->and($this->groceries->balance())->toBe(5000);
});

it('records a cross-currency (FX) expense balanced in base currency', function () {
    $txn = $this->record->create(
        $this->user,
        TransactionKind::Expense,
        '2026-06-17',
        [
            new PostingInput($this->amex->id, -10000, 'USD', -37000),
            new PostingInput($this->groceries->id, 37000, 'PEN', 37000),
        ],
    );

    expect($txn->isBalanced())->toBeTrue()
        ->and($this->amex->balance())->toBe(-10000)      // native USD owed
        ->and($this->amex->baseBalance())->toBe(-37000)  // translated to PEN
        ->and($this->groceries->balance())->toBe(37000);
});

it('records a transfer between own accounts without touching a category', function () {
    $this->record->create(
        $this->user,
        TransactionKind::Transfer,
        '2026-06-17',
        [
            new PostingInput($this->checking->id, -20000, 'PEN', -20000),
            new PostingInput($this->visa->id, 20000, 'PEN', 20000),
        ],
    );

    expect($this->checking->balance())->toBe(-20000)
        ->and($this->visa->balance())->toBe(20000)
        ->and($this->groceries->balance())->toBe(0);
});

it('records an N-line split in one balanced transaction', function () {
    $txn = $this->record->create(
        $this->user,
        TransactionKind::Expense,
        '2026-06-17',
        [
            new PostingInput($this->visa->id, -8000, 'PEN', -8000),
            new PostingInput($this->groceries->id, 5000, 'PEN', 5000),
            new PostingInput($this->dining->id, 3000, 'PEN', 3000),
        ],
    );

    expect($txn->postings()->count())->toBe(3)
        ->and($txn->isBalanced())->toBeTrue()
        ->and($this->visa->balance())->toBe(-8000);
});

it('records an opening balance against the equity account', function () {
    $this->record->create(
        $this->user,
        TransactionKind::Transfer,
        '2026-06-01',
        [
            new PostingInput($this->checking->id, 100000, 'PEN', 100000),
            new PostingInput($this->opening->id, -100000, 'PEN', -100000),
        ],
    );

    expect($this->checking->balance())->toBe(100000)
        ->and($this->opening->balance())->toBe(-100000);
});

it('computes account balance as the sum of its postings', function () {
    $this->record->create($this->user, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -5000, 'PEN', -5000),
        new PostingInput($this->groceries->id, 5000, 'PEN', 5000),
    ]);
    $this->record->create($this->user, TransactionKind::Expense, '2026-06-12', [
        new PostingInput($this->visa->id, -3000, 'PEN', -3000),
        new PostingInput($this->dining->id, 3000, 'PEN', 3000),
    ]);

    expect($this->visa->balance())->toBe(-8000)
        ->and($this->groceries->balance())->toBe(5000)
        ->and($this->dining->balance())->toBe(3000);
});

it('computes net worth over asset and liability accounts only', function () {
    // Seed checking with 1000.00, then spend 50.00 on the Visa.
    $this->record->create($this->user, TransactionKind::Transfer, '2026-06-01', [
        new PostingInput($this->checking->id, 100000, 'PEN', 100000),
        new PostingInput($this->opening->id, -100000, 'PEN', -100000),
    ]);
    $this->record->create($this->user, TransactionKind::Expense, '2026-06-05', [
        new PostingInput($this->visa->id, -5000, 'PEN', -5000),
        new PostingInput($this->groceries->id, 5000, 'PEN', 5000),
    ]);

    // checking +100000 + visa -5000 = 95000 (equity & expense excluded).
    expect($this->user->netWorth())->toBe(95000);
});

it('rejects an unbalanced posting set and persists nothing', function () {
    expect(fn () => $this->record->create($this->user, TransactionKind::Expense, '2026-06-17', [
        new PostingInput($this->visa->id, -5000, 'PEN', -5000),
        new PostingInput($this->groceries->id, 4000, 'PEN', 4000),
    ]))->toThrow(InvalidTransactionException::class);

    expect(Transaction::count())->toBe(0)
        ->and(Posting::count())->toBe(0);
});

it('rejects fewer than two postings', function () {
    expect(fn () => $this->record->create($this->user, TransactionKind::Expense, '2026-06-17', [
        new PostingInput($this->visa->id, 0, 'PEN', 0),
    ]))->toThrow(InvalidTransactionException::class);
});

it('rejects a posting to an account owned by another user', function () {
    $intruder = Account::factory()->expense()->create(); // belongs to a different user

    expect(fn () => $this->record->create($this->user, TransactionKind::Expense, '2026-06-17', [
        new PostingInput($this->visa->id, -5000, 'PEN', -5000),
        new PostingInput($intruder->id, 5000, 'PEN', 5000),
    ]))->toThrow(InvalidTransactionException::class);

    expect(Transaction::count())->toBe(0);
});

it('edits a transaction by atomically replacing its posting set', function () {
    $txn = $this->record->create($this->user, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -5000, 'PEN', -5000),
        new PostingInput($this->groceries->id, 5000, 'PEN', 5000),
    ]);

    $this->record->update($txn, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -7000, 'PEN', -7000),
        new PostingInput($this->dining->id, 7000, 'PEN', 7000),
    ]);

    expect(Posting::count())->toBe(2)               // old lines gone, not accumulated
        ->and($this->visa->balance())->toBe(-7000)
        ->and($this->groceries->balance())->toBe(0)
        ->and($this->dining->balance())->toBe(7000);
});

it('rejects an unbalanced edit and preserves the original', function () {
    $txn = $this->record->create($this->user, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -5000, 'PEN', -5000),
        new PostingInput($this->groceries->id, 5000, 'PEN', 5000),
    ]);

    expect(fn () => $this->record->update($txn, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -5000, 'PEN', -5000),
        new PostingInput($this->groceries->id, 4000, 'PEN', 4000),
    ]))->toThrow(InvalidTransactionException::class);

    expect(Posting::count())->toBe(2)
        ->and($this->visa->balance())->toBe(-5000)
        ->and($this->groceries->balance())->toBe(5000);
});

it('hard deletes a transaction and its postings, reverting balances', function () {
    $txn = $this->record->create($this->user, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -5000, 'PEN', -5000),
        new PostingInput($this->groceries->id, 5000, 'PEN', 5000),
    ]);

    $this->record->delete($txn);

    expect(Transaction::count())->toBe(0)
        ->and(Posting::count())->toBe(0)
        ->and($this->visa->balance())->toBe(0)
        ->and($this->groceries->balance())->toBe(0);
});

it('model backstop flags an unbalanced persisted transaction', function () {
    $txn = Transaction::factory()->for($this->user)->create(['kind' => TransactionKind::Expense]);
    Posting::factory()->create([
        'transaction_id' => $txn->id, 'user_id' => $this->user->id, 'account_id' => $this->visa->id,
        'amount' => -5000, 'base_amount' => -5000, 'currency' => 'PEN',
    ]);
    Posting::factory()->create([
        'transaction_id' => $txn->id, 'user_id' => $this->user->id, 'account_id' => $this->groceries->id,
        'amount' => 4000, 'base_amount' => 4000, 'currency' => 'PEN',
    ]);

    expect($txn->isBalanced())->toBeFalse();
    expect(fn () => $txn->assertBalanced())->toThrow(InvalidTransactionException::class);
});
