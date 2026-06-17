<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Posting;
use App\Models\Transaction;
use App\Models\User;
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

it('refuses to delete an account that has transactions', function () {
    $account = Account::factory()->expense()->for($this->user)->create();
    $txn = Transaction::factory()->for($this->user)->create();
    Posting::factory()->create([
        'transaction_id' => $txn->id, 'user_id' => $this->user->id, 'account_id' => $account->id,
        'amount' => 100, 'base_amount' => 100, 'currency' => 'PEN',
    ]);

    $this->delete(route('accounts.destroy', $account))->assertSessionHasErrors('account');

    expect(Account::find($account->id))->not->toBeNull();
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
