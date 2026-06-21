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

it('rejects an amount more precise than the account currency allows', function () {
    // Visa is PEN (2 decimals); 50.123 would otherwise be silently rounded.
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $this->groceries->id, 'amount' => '50.123',
    ])->assertSessionHasErrors('amount');

    expect(Transaction::count())->toBe(0);
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

it('records a foreign purchase natively in its own currency', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->amex->id, 'category_id' => $this->groceries->id,
        'amount' => '100',
    ])->assertRedirect();

    expect($this->amex->balance())->toBe(-10000)                       // native USD owed
        ->and($this->groceries->balancesByCurrency())->toBe(['USD' => 10000]);
});

it('accepts a foreign expense without any base amount', function () {
    // The old "amount in base" requirement is gone (decision #11).
    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->amex->id, 'category_id' => $this->groceries->id, 'amount' => '100',
    ])->assertValid()->assertRedirect();

    expect(Transaction::count())->toBe(1);
});

it('records a base→foreign transfer as a swap of the two observed amounts', function () {
    // Move S/370 out of PEN checking, $100 into the USD savings account — two amounts, no rate.
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id,
        'amount' => '370', 'to_amount' => '100',
    ])->assertRedirect();

    expect($this->checking->balance())->toBe(-37000)
        ->and($this->savingsUsd->balance())->toBe(10000)     // native USD applied
        ->and(Transaction::sole()->isBalanced())->toBeTrue();
});

it('records a foreign→base transfer (the reverse direction)', function () {
    // Sell $100 from USD savings into PEN checking — the from-is-foreign branch (review gap J).
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->savingsUsd->id, 'to_account_id' => $this->checking->id,
        'amount' => '100', 'to_amount' => '370',
    ])->assertRedirect();

    expect($this->savingsUsd->balance())->toBe(-10000)       // $100 left
        ->and($this->checking->balance())->toBe(37000)       // S/370 arrived
        ->and(Transaction::sole()->isBalanced())->toBeTrue();
});

it('rejects a cross-currency transfer that excludes base', function () {
    $euro = Account::factory()->asset('EUR')->for($this->user)->create(['name' => 'Euro wallet']);

    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->savingsUsd->id, 'to_account_id' => $euro->id,
        'amount' => '100', 'to_amount' => '92',
    ])->assertSessionHasErrors('to_account_id');

    expect(Transaction::count())->toBe(0);
});

it('requires the received amount on a cross-currency transfer', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id, 'amount' => '370',
    ])->assertSessionHasErrors('to_amount');
});

it('does not warn on the first exchange for a pair (it sets the baseline)', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id,
        'amount' => '370', 'to_amount' => '100',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Transaction::count())->toBe(1);
});

it('warns and asks to confirm when an exchange rate deviates from the last', function () {
    // Baseline: S/370 ↔ $100 (3.70).
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id,
        'amount' => '370', 'to_amount' => '100',
    ]);

    // A 10× slip: S/3,700 ↔ $100 (37.0). Soft-blocked pending confirmation.
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-18',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id,
        'amount' => '3700', 'to_amount' => '100',
    ])->assertSessionHasErrors('confirm_rate');

    expect(Transaction::count())->toBe(1); // only the baseline recorded
});

it('records a deviating exchange once the rate is confirmed', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id,
        'amount' => '370', 'to_amount' => '100',
    ]);

    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-18',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id,
        'amount' => '3700', 'to_amount' => '100', 'confirm_rate' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Transaction::count())->toBe(2);
});

it('does not warn when the exchange rate is close to the last', function () {
    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-17',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id,
        'amount' => '370', 'to_amount' => '100',   // 3.70
    ]);

    $this->post(route('transactions.store'), [
        'kind' => 'transfer', 'date' => '2026-06-18',
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->savingsUsd->id,
        'amount' => '385', 'to_amount' => '100',   // 3.85, within band
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Transaction::count())->toBe(2);
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

it('rejects posting to a group as the category', function () {
    $foodGroup = Account::factory()->expense()->group()->for($this->user)->create();

    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $foodGroup->id, 'amount' => '50',
    ])->assertSessionHasErrors('category_id');

    expect(Transaction::count())->toBe(0);
});

it('rejects posting to a group as the money account', function () {
    $assetGroup = Account::factory()->asset('PEN')->group()->for($this->user)->create();

    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $assetGroup->id, 'category_id' => $this->groceries->id, 'amount' => '50',
    ])->assertSessionHasErrors('account_id');
});

it('nests expense categories under their group for the picker, omitting empty groups', function () {
    $food = Account::factory()->expense()->group()->for($this->user)->create(['name' => 'Food']);
    Account::factory()->expense()->for($this->user)->create(['name' => 'Dining', 'parent_id' => $food->id]);
    Account::factory()->expense()->group()->for($this->user)->create(['name' => 'Empty Group']);

    $this->get(route('transactions.index'))->assertInertia(fn (Assert $page) => $page
        // Food (group) + Groceries (ungrouped leaf from beforeEach); the childless
        // "Empty Group" is omitted. Top level is name-ordered, so Food precedes Groceries.
        ->has('expenseCategories', 2)
        ->where('expenseCategories.0.name', 'Food')
        ->where('expenseCategories.0.is_group', true)
        ->has('expenseCategories.0.children', 1)
        ->where('expenseCategories.0.children.0.name', 'Dining')
        ->where('expenseCategories.0.children.0.is_group', false)
        ->where('expenseCategories.1.name', 'Groceries')
        ->where('expenseCategories.1.is_group', false)
    );
});

it('records normally against a leaf category nested under a group', function () {
    $food = Account::factory()->expense()->group()->for($this->user)->create(['name' => 'Food']);
    $groceries = Account::factory()->expense()->for($this->user)->create(['name' => 'Groceries', 'parent_id' => $food->id]);

    $this->post(route('transactions.store'), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $groceries->id, 'amount' => '50',
    ])->assertRedirect();

    expect($groceries->balance())->toBe(5000)
        ->and($this->visa->balance())->toBe(-5000);
});

it('cannot edit or delete another user\'s transaction', function () {
    $theirUser = User::factory()->onboarded('PEN')->create();
    $theirAccount = Account::factory()->asset('PEN')->for($theirUser)->create();
    $theirCategory = Account::factory()->expense()->for($theirUser)->create();
    $theirTxn = app(RecordTransaction::class)->create(
        $theirUser, TransactionKind::Expense, '2026-06-17',
        [
            new PostingInput($theirAccount->id, -5000, 'PEN'),
            new PostingInput($theirCategory->id, 5000, 'PEN'),
        ],
    );

    $this->put(route('transactions.update', $theirTxn), [
        'kind' => 'expense', 'date' => '2026-06-17',
        'account_id' => $this->visa->id, 'category_id' => $this->groceries->id, 'amount' => '10',
    ])->assertNotFound();

    $this->delete(route('transactions.destroy', $theirTxn))->assertNotFound();
});
