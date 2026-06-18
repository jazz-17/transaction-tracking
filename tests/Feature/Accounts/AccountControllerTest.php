<?php

use App\Actions\Transactions\PostingInput;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\AccountType;
use App\Enums\TransactionKind;
use App\Models\Account;
use App\Models\Posting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['base_currency' => 'PEN']);
    $this->actingAs($this->user);
});

it('lists accounts split into My Accounts and Categories, hiding equity', function () {
    Account::factory()->asset('PEN')->for($this->user)->create(['name' => 'Checking']);
    Account::factory()->expense()->for($this->user)->create(['name' => 'Groceries']);
    Account::factory()->equity()->for($this->user)->create(['name' => 'Opening Balances']);

    $this->get(route('accounts.index'))->assertInertia(fn (Assert $page) => $page
        ->component('accounts/Index')
        ->has('myAccounts', 1)
        ->has('categories', 1)
        ->where('baseCurrency', 'PEN')
    );
});

it('only lists the current user\'s accounts', function () {
    Account::factory()->asset('PEN')->for($this->user)->create();
    Account::factory()->asset('PEN')->create(); // another user

    $this->get(route('accounts.index'))->assertInertia(fn (Assert $page) => $page->has('myAccounts', 1));
});

it('creates a liability account with its native currency', function () {
    $this->post(route('accounts.store'), [
        'name' => 'Amex', 'type' => 'liability', 'currency' => 'USD',
    ])->assertRedirect();

    $account = Account::where('name', 'Amex')->first();
    expect($account->type)->toBe(AccountType::Liability)
        ->and($account->currency)->toBe('USD')
        ->and($account->user_id)->toBe($this->user->id);
});

it('creates a category without a currency', function () {
    $this->post(route('accounts.store'), ['name' => 'Travel', 'type' => 'expense'])->assertValid();

    expect(Account::where('name', 'Travel')->first()->currency)->toBeNull();
});

it('requires a currency for My Accounts', function () {
    $this->post(route('accounts.store'), ['name' => 'Bank', 'type' => 'asset'])
        ->assertSessionHasErrors('currency');
});

it('refuses to create a system equity account from the UI', function () {
    $this->post(route('accounts.store'), ['name' => 'Hack', 'type' => 'equity'])
        ->assertSessionHasErrors('type');
});

it('updates name and archived state', function () {
    $account = Account::factory()->expense()->for($this->user)->create(['name' => 'Old']);

    $this->put(route('accounts.update', $account), ['name' => 'New', 'archived' => true])->assertRedirect();

    $account->refresh();
    expect($account->name)->toBe('New')->and($account->archived)->toBeTrue();
});

it('deletes an account with no postings', function () {
    $account = Account::factory()->expense()->for($this->user)->create();

    $this->delete(route('accounts.destroy', $account))->assertRedirect();

    expect(Account::find($account->id))->toBeNull();
});

it('refuses to delete an account that has real transactions', function () {
    $account = Account::factory()->asset('PEN')->for($this->user)->create();
    $category = Account::factory()->expense()->for($this->user)->create();
    $txn = Transaction::factory()->for($this->user)->create();
    Posting::factory()->create([
        'transaction_id' => $txn->id, 'user_id' => $this->user->id, 'account_id' => $account->id,
        'amount' => -5000, 'base_amount' => -5000, 'currency' => 'PEN',
    ]);
    Posting::factory()->create([
        'transaction_id' => $txn->id, 'user_id' => $this->user->id, 'account_id' => $category->id,
        'amount' => 5000, 'base_amount' => 5000, 'currency' => 'PEN',
    ]);

    $this->delete(route('accounts.destroy', $account))->assertSessionHasErrors('account');

    expect(Account::find($account->id))->not->toBeNull();
});

it('hard-deletes a My Account whose only history is its opening-balance seed', function () {
    $equity = Account::factory()->equity()->for($this->user)->create();

    $this->post(route('accounts.store'), [
        'name' => 'Checking', 'type' => 'asset', 'currency' => 'PEN', 'opening_balance' => '1000',
    ])->assertRedirect();

    $checking = Account::where('name', 'Checking')->sole();
    expect(Transaction::count())->toBe(1); // the seed

    $this->delete(route('accounts.destroy', $checking))->assertValid()->assertRedirect();

    expect(Account::find($checking->id))->toBeNull()    // account gone
        ->and(Transaction::count())->toBe(0)            // seed transaction removed
        ->and(Posting::count())->toBe(0)                // both legs cascaded
        ->and($equity->refresh()->balance())->toBe(0);  // equity rebalanced, books intact
});

it('refuses to delete the hidden equity account', function () {
    $equity = Account::factory()->equity()->for($this->user)->create();

    $this->delete(route('accounts.destroy', $equity))->assertForbidden();

    expect(Account::find($equity->id))->not->toBeNull();
});

it('cannot update or delete another user\'s account', function () {
    $theirs = Account::factory()->expense()->create();

    $this->put(route('accounts.update', $theirs), ['name' => 'x'])->assertNotFound();
    $this->delete(route('accounts.destroy', $theirs))->assertNotFound();
});

it('seeds an opening balance for a new asset account against equity', function () {
    $equity = Account::factory()->equity()->for($this->user)->create(['name' => 'Opening Balances']);

    $this->post(route('accounts.store'), [
        'name' => 'Checking', 'type' => 'asset', 'currency' => 'PEN', 'opening_balance' => '1000',
    ])->assertRedirect();

    $checking = Account::where('name', 'Checking')->sole();
    expect($checking->balance())->toBe(100000)        // +S/1000 you have
        ->and($equity->balance())->toBe(-100000)      // balanced against equity
        ->and($this->user->netWorth())->toBe(100000);
});

it('seeds a liability opening balance as money owed', function () {
    Account::factory()->equity()->for($this->user)->create();

    $this->post(route('accounts.store'), [
        'name' => 'Amex', 'type' => 'liability', 'currency' => 'PEN', 'opening_balance' => '500',
    ])->assertRedirect();

    $amex = Account::where('name', 'Amex')->sole();
    expect($amex->balance())->toBe(-50000)            // stored negative (you owe)
        ->and($this->user->netWorth())->toBe(-50000); // reduces net worth
});

it('seeds an FX opening balance using the supplied base value', function () {
    Account::factory()->equity()->for($this->user)->create();

    $this->post(route('accounts.store'), [
        'name' => 'USD Savings', 'type' => 'asset', 'currency' => 'USD',
        'opening_balance' => '100', 'opening_balance_base' => '370',
    ])->assertRedirect();

    $savings = Account::where('name', 'USD Savings')->sole();
    expect($savings->balance())->toBe(10000)          // native USD
        ->and($savings->baseBalance())->toBe(37000)   // translated to PEN
        ->and($this->user->netWorth())->toBe(37000);
});

it('requires a base value for an FX opening balance', function () {
    Account::factory()->equity()->for($this->user)->create();

    $this->post(route('accounts.store'), [
        'name' => 'USD Savings', 'type' => 'asset', 'currency' => 'USD', 'opening_balance' => '100',
    ])->assertSessionHasErrors('opening_balance_base');

    expect(Account::where('name', 'USD Savings')->exists())->toBeFalse();
});

it('creates an account with no transaction when no opening balance is given', function () {
    Account::factory()->equity()->for($this->user)->create();

    $this->post(route('accounts.store'), [
        'name' => 'Checking', 'type' => 'asset', 'currency' => 'PEN',
    ])->assertRedirect();

    expect(Transaction::count())->toBe(0);
});

it('rolls back the account when opening-balance seeding fails', function () {
    // No equity account exists, so seedOpeningBalance's firstOrFail throws mid-seed. The
    // account creation must roll back rather than leave a balance-less account behind.
    $this->post(route('accounts.store'), [
        'name' => 'Checking', 'type' => 'asset', 'currency' => 'PEN', 'opening_balance' => '1000',
    ])->assertNotFound();

    expect(Account::where('name', 'Checking')->exists())->toBeFalse();
});

it('rejects an opening balance more precise than the currency allows', function () {
    Account::factory()->equity()->for($this->user)->create();

    // PEN has 2 decimals; 12.345 would otherwise be silently rounded by Money::parse.
    $this->post(route('accounts.store'), [
        'name' => 'Checking', 'type' => 'asset', 'currency' => 'PEN', 'opening_balance' => '12.345',
    ])->assertSessionHasErrors('opening_balance');

    expect(Account::where('name', 'Checking')->exists())->toBeFalse();
});

it('accepts a zero opening balance as no starting balance', function () {
    Account::factory()->equity()->for($this->user)->create();

    $this->post(route('accounts.store'), [
        'name' => 'Visa', 'type' => 'liability', 'currency' => 'PEN', 'opening_balance' => '0',
    ])->assertValid()->assertRedirect();

    expect(Account::where('name', 'Visa')->exists())->toBeTrue()
        ->and(Transaction::count())->toBe(0);
});

// --- Category hierarchy (Phase 5, decision #13) -----------------------------------

it('creates a category group with no currency', function () {
    $this->post(route('accounts.store'), [
        'name' => 'Food', 'type' => 'expense', 'is_group' => true,
    ])->assertValid()->assertRedirect();

    $group = Account::where('name', 'Food')->sole();
    expect($group->is_group)->toBeTrue()
        ->and($group->currency)->toBeNull()
        ->and($group->parent_id)->toBeNull();
});

it('creates a leaf nested under a same-type root group', function () {
    $food = Account::factory()->expense()->group()->for($this->user)->create(['name' => 'Food']);

    $this->post(route('accounts.store'), [
        'name' => 'Groceries', 'type' => 'expense', 'parent_id' => $food->id,
    ])->assertValid()->assertRedirect();

    expect(Account::where('name', 'Groceries')->sole()->parent_id)->toBe($food->id);
});

it('rejects a leaf whose parent is a different type', function () {
    $incomeGroup = Account::factory()->income()->group()->for($this->user)->create();

    $this->post(route('accounts.store'), [
        'name' => 'Groceries', 'type' => 'expense', 'parent_id' => $incomeGroup->id,
    ])->assertSessionHasErrors('parent_id');
});

it('rejects a leaf whose parent is itself a leaf, not a group', function () {
    $leaf = Account::factory()->expense()->for($this->user)->create();

    $this->post(route('accounts.store'), [
        'name' => 'Groceries', 'type' => 'expense', 'parent_id' => $leaf->id,
    ])->assertSessionHasErrors('parent_id');
});

it('rejects nesting under a non-root group, enforcing the 2-level cap', function () {
    // A group with a parent can only exist via tampering — the write rules never make one.
    // A leaf still may not nest under it: parents must be roots.
    $root = Account::factory()->expense()->group()->for($this->user)->create();
    $nested = Account::factory()->expense()->group()->for($this->user)->create(['parent_id' => $root->id]);

    $this->post(route('accounts.store'), [
        'name' => 'Groceries', 'type' => 'expense', 'parent_id' => $nested->id,
    ])->assertSessionHasErrors('parent_id');
});

it('rejects giving a group a parent', function () {
    $root = Account::factory()->expense()->group()->for($this->user)->create();

    $this->post(route('accounts.store'), [
        'name' => 'Food', 'type' => 'expense', 'is_group' => true, 'parent_id' => $root->id,
    ])->assertSessionHasErrors('parent_id');
});

it('ignores is_group on update because it is immutable', function () {
    $leaf = Account::factory()->expense()->for($this->user)->create(['name' => 'Groceries']);

    $this->put(route('accounts.update', $leaf), ['name' => 'Groceries', 'is_group' => true])->assertRedirect();

    expect($leaf->refresh()->is_group)->toBeFalse();
});

it('re-homes a leaf under a same-type root group on update', function () {
    $food = Account::factory()->expense()->group()->for($this->user)->create(['name' => 'Food']);
    $leaf = Account::factory()->expense()->for($this->user)->create(['name' => 'Groceries']);

    $this->put(route('accounts.update', $leaf), ['name' => 'Groceries', 'parent_id' => $food->id])->assertRedirect();

    expect($leaf->refresh()->parent_id)->toBe($food->id);
});

it('rejects re-homing a leaf under a different-type group on update', function () {
    $incomeGroup = Account::factory()->income()->group()->for($this->user)->create();
    $leaf = Account::factory()->expense()->for($this->user)->create(['name' => 'Groceries']);

    $this->put(route('accounts.update', $leaf), ['name' => 'Groceries', 'parent_id' => $incomeGroup->id])
        ->assertSessionHasErrors('parent_id');
});

it('blocks deleting a group that still has children', function () {
    $food = Account::factory()->expense()->group()->for($this->user)->create();
    Account::factory()->expense()->for($this->user)->create(['parent_id' => $food->id]);

    $this->delete(route('accounts.destroy', $food))->assertSessionHasErrors('account');

    expect(Account::find($food->id))->not->toBeNull();
});

it('deletes an empty group', function () {
    $food = Account::factory()->expense()->group()->for($this->user)->create();

    $this->delete(route('accounts.destroy', $food))->assertValid()->assertRedirect();

    expect(Account::find($food->id))->toBeNull();
});

it('rolls a group balance up from its children', function () {
    $food = Account::factory()->expense()->group()->for($this->user)->create(['name' => 'Food']);
    $groceries = Account::factory()->expense()->for($this->user)->create(['name' => 'Groceries', 'parent_id' => $food->id]);
    $coffee = Account::factory()->expense()->for($this->user)->create(['name' => 'Coffee', 'parent_id' => $food->id]);
    $visa = Account::factory()->liability('PEN')->for($this->user)->create(['name' => 'Visa']);

    app(RecordTransaction::class)->create($this->user, TransactionKind::Expense, '2026-06-17', [
        new PostingInput($visa->id, -8000, 'PEN', -8000),
        new PostingInput($groceries->id, 5000, 'PEN', 5000),
        new PostingInput($coffee->id, 3000, 'PEN', 3000),
    ]);

    // Categories are ordered by name: Coffee(0), Food(1), Groceries(2).
    $this->get(route('accounts.index'))->assertInertia(fn (Assert $page) => $page
        ->where('categories.1.name', 'Food')
        ->where('categories.1.is_group', true)
        ->where('categories.1.balance_display', Money::ofMinor(8000, 'PEN')->format()) // 5000 + 3000
        ->where('categories.0.balance_display', Money::ofMinor(3000, 'PEN')->format()) // Coffee
        ->where('categories.2.balance_display', Money::ofMinor(5000, 'PEN')->format()) // Groceries
    );
});
