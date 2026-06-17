<?php

use App\Models\Account;
use App\Models\User;

it('auto-fills user_id from the authenticated user on create', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $account = Account::factory()->expense()->create(['user_id' => null]);

    expect($account->user_id)->toBe($user->id);
});

it('scopes queries to the authenticated user', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    Account::factory()->expense()->for($me)->create(['name' => 'Mine']);
    Account::factory()->expense()->for($other)->create(['name' => 'Theirs']);

    $this->actingAs($me);

    expect(Account::query()->pluck('name')->all())->toBe(['Mine']);
});

it('cannot read another user\'s record even by id', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $theirs = Account::factory()->expense()->for($other)->create();

    $this->actingAs($me);

    expect(Account::query()->find($theirs->id))->toBeNull();
});

it('exposes the owning user relationship', function () {
    $user = User::factory()->create();
    $account = Account::factory()->expense()->for($user)->create();

    expect($account->user->is($user))->toBeTrue();
});
