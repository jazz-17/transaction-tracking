<?php

use App\Actions\Transactions\PostingInput;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\TransactionKind;
use App\Models\Account;
use App\Models\Posting;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->onboarded('PEN')->create();
    $this->actingAs($this->user);

    $this->checking = Account::factory()->asset('PEN')->for($this->user)->create(['name' => 'Checking']);
    $this->visa = Account::factory()->liability('PEN')->for($this->user)->create(['name' => 'Visa']);
    $this->amex = Account::factory()->liability('USD')->for($this->user)->create(['name' => 'Amex']);
    $this->savingsUsd = Account::factory()->asset('USD')->for($this->user)->create(['name' => 'USD Savings']);
    $this->groceries = Account::factory()->expense()->for($this->user)->create(['name' => 'Groceries']);
    $this->salary = Account::factory()->income()->for($this->user)->create(['name' => 'Salary']);
});

it('exposes only money accounts and matching categories to the form', function () {
    Account::factory()->expense()->for($this->user)->archived()->create(); // hidden from pickers

    $this->get(route('transactions.index'))->assertInertia(fn (Assert $page) => $page
        ->component('transactions/Index')
        ->has('accounts', 4) // checking, visa, amex, USD savings
        ->has('expenseCategories', 1)
        ->has('incomeCategories', 1)
        ->where('baseCurrency', 'PEN')
    );
});

it('records a single-currency expense end to end', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $this->groceries->id,
        'amount' => '50', 'payee' => 'Wong',
    ])->assertRedirect();

    $txn = Transaction::sole();
    expect($txn->kind)->toBe(TransactionKind::Expense)
        ->and($txn->payee)->toBe('Wong')
        ->and($txn->postings()->count())->toBe(2)
        ->and($this->visa->balance())->toBe(-5000)
        ->and($this->groceries->balance())->toBe(5000);
});

it('records an income end to end', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'income', 'date' => '2026-06-17',
        'account_id' => $this->checking->id, 'category_id' => $this->salary->id, 'amount' => '1200',
    ])->assertRedirect();

    expect($this->checking->balance())->toBe(120000)
        ->and($this->salary->balance())->toBe(-120000); // income normal-negative
});

it('records a same-currency transfer without touching a category', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->visa->id, 'amount' => '200',
    ])->assertRedirect();

    expect($this->checking->balance())->toBe(-20000)
        ->and($this->visa->balance())->toBe(20000)
        ->and($this->groceries->balance())->toBe(0);
});

it('records an FX expense balanced in base currency', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->amex->id, 'category_id' => $this->groceries->id,
        'amount' => '100', 'base_amount' => '370',
    ])->assertRedirect();

    expect($this->amex->balance())->toBe(-10000)        // native USD owed
        ->and($this->amex->baseBalance())->toBe(-37000) // translated to PEN
        ->and($this->groceries->balance())->toBe(37000);
});

it('requires a base amount when the account currency differs from base', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->amex->id, 'category_id' => $this->groceries->id, 'amount' => '100',
    ])->assertSessionHasErrors('base_amount');

    expect(Transaction::count())->toBe(0);
});

it('records a cross-currency transfer, deriving base from the base-currency leg', function () {
    // Move S/370 out of PEN checking, $100 into the USD savings account.
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id,
        'amount' => '370', 'to_amount' => '100',
    ])->assertRedirect();

    expect($this->checking->balance())->toBe(-37000)
        ->and($this->savingsUsd->balance())->toBe(10000)     // native USD
        ->and($this->savingsUsd->baseBalance())->toBe(37000) // PEN value
        ->and(Transaction::sole()->isBalanced())->toBeTrue();
});

it('requires the received amount on a cross-currency transfer', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id, 'amount' => '370',
    ])->assertSessionHasErrors('to_amount');
});

it('exposes an edit payload that round-trips a single-currency expense', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $this->groceries->id, 'amount' => '50',
    ]);

    $this->get(route('transactions.index'))->assertInertia(fn (Assert $page) => $page
        ->missing('transactions') // deferred: absent on first load
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('transactions.0.kind', 'expense')
            ->where('transactions.0.direction', 'out')
            ->where('transactions.0.edit.account_id', $this->visa->id)
            ->where('transactions.0.edit.category_id', $this->groceries->id)
            ->where('transactions.0.edit.amount', '50.00')
        )
    );
});

it('edits a transaction by replacing its posting set', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $this->groceries->id, 'amount' => '50',
    ]);
    $txn = Transaction::sole();

    $this->put(route('transactions.update', $txn), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $this->groceries->id, 'amount' => '80',
    ])->assertRedirect();

    expect(Posting::count())->toBe(2)
        ->and($this->visa->balance())->toBe(-8000)
        ->and($this->groceries->balance())->toBe(8000);
});

it('deletes a transaction and reverts balances', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $this->groceries->id, 'amount' => '50',
    ]);
    $txn = Transaction::sole();

    $this->delete(route('transactions.destroy', $txn))->assertRedirect();

    expect(Transaction::count())->toBe(0)
        ->and(Posting::count())->toBe(0)
        ->and($this->visa->balance())->toBe(0);
});

it('rejects a category whose type does not match the chosen tab', function () {
    // Using an income category on the expense tab.
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $this->salary->id, 'amount' => '50',
    ])->assertSessionHasErrors('category_id');
});

it('rejects a category used as the money account', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->groceries->id, 'category_id' => $this->groceries->id, 'amount' => '50',
    ])->assertSessionHasErrors('account_id');
});

it('rejects a transfer to the same account', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->checking->id, 'amount' => '50',
    ])->assertSessionHasErrors('from_account_id');
});

it('rejects posting to another user\'s account', function () {
    $intruder = Account::factory()->expense()->create(); // belongs to another user

    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $intruder->id, 'amount' => '50',
    ])->assertSessionHasErrors('category_id');

    expect(Transaction::count())->toBe(0);
});

it('cannot edit or delete another user\'s transaction', function () {
    $theirUser = User::factory()->onboarded('PEN')->create();
    $theirAccount = Account::factory()->asset('PEN')->for($theirUser)->create();
    $theirCategory = Account::factory()->expense()->for($theirUser)->create();
    $theirTxn = app(RecordTransaction::class)->create(
        $theirUser, TransactionKind::Expense, '2026-06-17',
        [
            new PostingInput($theirAccount->id, -5000, 'PEN', -5000),
            new PostingInput($theirCategory->id, 5000, 'PEN', 5000),
        ],
    );

    $this->put(route('transactions.update', $theirTxn), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $this->groceries->id, 'amount' => '10',
    ])->assertNotFound();

    $this->delete(route('transactions.destroy', $theirTxn))->assertNotFound();
});
