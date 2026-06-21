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
            new PostingInput($this->visa->id, -5000, 'PEN'),
            new PostingInput($this->groceries->id, 5000, 'PEN'),
        ],
        payee: 'Wong',
    );

    expect($txn->postings()->count())->toBe(2)
        ->and($txn->payee)->toBe('Wong')
        ->and($txn->isBalanced())->toBeTrue()
        ->and($this->visa->balance())->toBe(-5000)
        ->and($this->groceries->balance())->toBe(5000);
});

it('records a foreign purchase in its own currency with no rate', function () {
    // $100 on the USD card → two USD legs; no PEN, no rate (decisions #4/#11/#14).
    $txn = $this->record->create(
        $this->user,
        TransactionKind::Expense,
        '2026-06-17',
        [
            new PostingInput($this->amex->id, -10000, 'USD'),
            new PostingInput($this->groceries->id, 10000, 'USD'),
        ],
    );

    expect($txn->isBalanced())->toBeTrue()
        ->and($this->amex->balance())->toBe(-10000)                  // native USD owed
        ->and($this->groceries->balancesByCurrency())->toBe(['USD' => 10000]);
});

it('records a cross-currency exchange paying the USD card by converting soles', function () {
    // First accrue $300 of USD debt on the card.
    $this->record->create($this->user, TransactionKind::Expense, '2026-06-01', [
        new PostingInput($this->amex->id, -30000, 'USD'),
        new PostingInput($this->groceries->id, 30000, 'USD'),
    ]);

    // Pay it off: S/1,140 debited buys $300 applied — two observed amounts, no rate (decision #16).
    // The card hits $0 purely via USD conservation on its own books; the PEN leg lives on checking.
    $txn = $this->record->create($this->user, TransactionKind::Transfer, '2026-06-15', [
        new PostingInput($this->checking->id, -114000, 'PEN'),
        new PostingInput($this->amex->id, 30000, 'USD'),
    ]);

    expect($txn->isBalanced())->toBeTrue()
        ->and($this->checking->balance())->toBe(-114000)
        ->and($this->amex->balance())->toBe(0);                      // paid off exactly — no phantom
});

it('accepts a cross-currency swap regardless of the implied rate (no weighted check)', function () {
    // The old weighted check rejected this (−120000 PEN vs +30000 USD implies 4.00, not 3.80).
    // The two amounts are observed facts now; only the soft deviation guard would warn (decision #16).
    $txn = $this->record->create($this->user, TransactionKind::Transfer, '2026-06-15', [
        new PostingInput($this->checking->id, -120000, 'PEN'),
        new PostingInput($this->amex->id, 30000, 'USD'),
    ]);

    expect($txn->isBalanced())->toBeTrue()
        ->and($this->checking->balance())->toBe(-120000)
        ->and($this->amex->balance())->toBe(30000);
});

it('records an exchange that includes a base-currency fee leg', function () {
    // S/1,150 leaves checking: S/1,140 buys $300, S/10 is a PEN bank fee — a clean structural swap.
    $txn = $this->record->create($this->user, TransactionKind::Transfer, '2026-06-15', [
        new PostingInput($this->checking->id, -115000, 'PEN'),
        new PostingInput($this->dining->id, 1000, 'PEN'),            // S/10 fee (a category)
        new PostingInput($this->amex->id, 30000, 'USD'),
    ]);

    expect($txn->isBalanced())->toBeTrue()
        ->and($this->checking->balance())->toBe(-115000)
        ->and($this->amex->balance())->toBe(30000);
});

it('records a transfer between own accounts without touching a category', function () {
    $this->record->create(
        $this->user,
        TransactionKind::Transfer,
        '2026-06-17',
        [
            new PostingInput($this->checking->id, -20000, 'PEN'),
            new PostingInput($this->visa->id, 20000, 'PEN'),
        ],
    );

    expect($this->checking->balance())->toBe(-20000)
        ->and($this->visa->balance())->toBe(20000)
        ->and($this->groceries->balance())->toBe(0);
});

it('records against a leaf category nested under a group', function () {
    $food = Account::factory()->expense()->group()->for($this->user)->create(['name' => 'Food']);
    $groceries = Account::factory()->expense()->for($this->user)->create(['name' => 'Sub Groceries', 'parent_id' => $food->id]);

    $txn = $this->record->create($this->user, TransactionKind::Expense, '2026-06-17', [
        new PostingInput($this->visa->id, -5000, 'PEN'),
        new PostingInput($groceries->id, 5000, 'PEN'),
    ]);

    expect($txn->isBalanced())->toBeTrue()
        ->and($groceries->balance())->toBe(5000);
});

it('records an N-line split in one balanced transaction', function () {
    $txn = $this->record->create(
        $this->user,
        TransactionKind::Expense,
        '2026-06-17',
        [
            new PostingInput($this->visa->id, -8000, 'PEN'),
            new PostingInput($this->groceries->id, 5000, 'PEN'),
            new PostingInput($this->dining->id, 3000, 'PEN'),
        ],
    );

    expect($txn->postings()->count())->toBe(3)
        ->and($txn->isBalanced())->toBeTrue()
        ->and($this->visa->balance())->toBe(-8000);
});

it('records a base-currency opening balance against equity', function () {
    $this->record->create(
        $this->user,
        TransactionKind::Transfer,
        '2026-06-01',
        [
            new PostingInput($this->checking->id, 100000, 'PEN'),
            new PostingInput($this->opening->id, -100000, 'PEN'),
        ],
    );

    expect($this->checking->balance())->toBe(100000)
        ->and($this->opening->balance())->toBe(-100000);
});

it('records a foreign opening balance natively against equity', function () {
    // A USD card you already owe $300 on — native, no PEN field (decision #14).
    $this->record->create(
        $this->user,
        TransactionKind::Transfer,
        '2026-06-01',
        [
            new PostingInput($this->amex->id, -30000, 'USD'),
            new PostingInput($this->opening->id, 30000, 'USD'),
        ],
    );

    expect($this->amex->balance())->toBe(-30000)
        ->and($this->opening->balancesByCurrency())->toBe(['USD' => 30000]);
});

it('computes account balance as the sum of its postings', function () {
    $this->record->create($this->user, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -5000, 'PEN'),
        new PostingInput($this->groceries->id, 5000, 'PEN'),
    ]);
    $this->record->create($this->user, TransactionKind::Expense, '2026-06-12', [
        new PostingInput($this->visa->id, -3000, 'PEN'),
        new PostingInput($this->dining->id, 3000, 'PEN'),
    ]);

    expect($this->visa->balance())->toBe(-8000)
        ->and($this->groceries->balance())->toBe(5000)
        ->and($this->dining->balance())->toBe(3000);
});

it('computes net worth as per-currency buckets over asset and liability accounts', function () {
    // Seed checking S/1000, spend S/50 on the Visa, owe $300 on the USD card.
    $this->record->create($this->user, TransactionKind::Transfer, '2026-06-01', [
        new PostingInput($this->checking->id, 100000, 'PEN'),
        new PostingInput($this->opening->id, -100000, 'PEN'),
    ]);
    $this->record->create($this->user, TransactionKind::Expense, '2026-06-05', [
        new PostingInput($this->visa->id, -5000, 'PEN'),
        new PostingInput($this->groceries->id, 5000, 'PEN'),
    ]);
    $this->record->create($this->user, TransactionKind::Expense, '2026-06-06', [
        new PostingInput($this->amex->id, -30000, 'USD'),
        new PostingInput($this->groceries->id, 30000, 'USD'),
    ]);

    // PEN: checking 100000 + visa -5000 = 95000; USD: amex -30000. Categories/equity excluded.
    expect($this->user->netWorth())->toBe(['PEN' => 95000, 'USD' => -30000]);
});

it('rejects an unbalanced single-currency posting set and persists nothing', function () {
    expect(fn () => $this->record->create($this->user, TransactionKind::Expense, '2026-06-17', [
        new PostingInput($this->visa->id, -5000, 'PEN'),
        new PostingInput($this->groceries->id, 4000, 'PEN'),
    ]))->toThrow(InvalidTransactionException::class);

    expect(Transaction::count())->toBe(0)
        ->and(Posting::count())->toBe(0);
});

it('rejects a transaction touching three currencies', function () {
    $euro = Account::factory()->liability('EUR')->for($this->user)->create(['name' => 'Euro card']);

    expect(fn () => $this->record->create($this->user, TransactionKind::Transfer, '2026-06-15', [
        new PostingInput($this->checking->id, -114000, 'PEN'),
        new PostingInput($this->amex->id, 30000, 'USD'),
        new PostingInput($euro->id, 10000, 'EUR'),
    ]))->toThrow(InvalidTransactionException::class);
});

it('rejects a foreign-to-foreign exchange that excludes base', function () {
    $euro = Account::factory()->liability('EUR')->for($this->user)->create(['name' => 'Euro card']);

    expect(fn () => $this->record->create($this->user, TransactionKind::Transfer, '2026-06-15', [
        new PostingInput($this->amex->id, -30000, 'USD'),
        new PostingInput($euro->id, 27600, 'EUR'),
    ]))->toThrow(InvalidTransactionException::class);
});

it('rejects fewer than two postings', function () {
    expect(fn () => $this->record->create($this->user, TransactionKind::Expense, '2026-06-17', [
        new PostingInput($this->visa->id, 0, 'PEN'),
    ]))->toThrow(InvalidTransactionException::class);
});

it('rejects a posting to an account owned by another user', function () {
    $intruder = Account::factory()->expense()->create(); // belongs to a different user

    expect(fn () => $this->record->create($this->user, TransactionKind::Expense, '2026-06-17', [
        new PostingInput($this->visa->id, -5000, 'PEN'),
        new PostingInput($intruder->id, 5000, 'PEN'),
    ]))->toThrow(InvalidTransactionException::class);

    expect(Transaction::count())->toBe(0);
});

it('rejects a My Account posting not in its locked native currency', function () {
    // Amex is USD-locked; a PEN leg on it is invalid (decision #14).
    expect(fn () => $this->record->create($this->user, TransactionKind::Expense, '2026-06-17', [
        new PostingInput($this->amex->id, -37000, 'PEN'),
        new PostingInput($this->groceries->id, 37000, 'PEN'),
    ]))->toThrow(InvalidTransactionException::class);

    expect(Transaction::count())->toBe(0)
        ->and(Posting::count())->toBe(0);
});

it('allows a category posting in any currency', function () {
    // Categories are multi-currency now (decision #14): a USD grocery leg is valid.
    $txn = $this->record->create($this->user, TransactionKind::Expense, '2026-06-17', [
        new PostingInput($this->amex->id, -5000, 'USD'),
        new PostingInput($this->groceries->id, 5000, 'USD'),
    ]);

    expect($txn->isBalanced())->toBeTrue()
        ->and($this->groceries->balancesByCurrency())->toBe(['USD' => 5000]);
});

it('edits a transaction by atomically replacing its posting set', function () {
    $txn = $this->record->create($this->user, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -5000, 'PEN'),
        new PostingInput($this->groceries->id, 5000, 'PEN'),
    ]);

    $this->record->update($txn, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -7000, 'PEN'),
        new PostingInput($this->dining->id, 7000, 'PEN'),
    ]);

    expect(Posting::count())->toBe(2)               // old lines gone, not accumulated
        ->and($this->visa->balance())->toBe(-7000)
        ->and($this->groceries->balance())->toBe(0)
        ->and($this->dining->balance())->toBe(7000);
});

it('rejects an unbalanced edit and preserves the original', function () {
    $txn = $this->record->create($this->user, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -5000, 'PEN'),
        new PostingInput($this->groceries->id, 5000, 'PEN'),
    ]);

    expect(fn () => $this->record->update($txn, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -5000, 'PEN'),
        new PostingInput($this->groceries->id, 4000, 'PEN'),
    ]))->toThrow(InvalidTransactionException::class);

    expect(Posting::count())->toBe(2)
        ->and($this->visa->balance())->toBe(-5000)
        ->and($this->groceries->balance())->toBe(5000);
});

it('hard deletes a transaction and its postings, reverting balances', function () {
    $txn = $this->record->create($this->user, TransactionKind::Expense, '2026-06-10', [
        new PostingInput($this->visa->id, -5000, 'PEN'),
        new PostingInput($this->groceries->id, 5000, 'PEN'),
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
        'amount' => -5000, 'currency' => 'PEN',
    ]);
    Posting::factory()->create([
        'transaction_id' => $txn->id, 'user_id' => $this->user->id, 'account_id' => $this->groceries->id,
        'amount' => 4000, 'currency' => 'PEN',
    ]);

    expect($txn->isBalanced())->toBeFalse();
    expect(fn () => $txn->assertBalanced())->toThrow(InvalidTransactionException::class);
});
