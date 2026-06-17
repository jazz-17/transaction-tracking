<?php

use App\Actions\ProvisionNewUserLedger;
use App\Enums\AccountType;
use App\Models\User;

it('seeds the equity account, a Cash asset, and categories', function () {
    $user = User::factory()->create(['base_currency' => 'PEN']);

    app(ProvisionNewUserLedger::class)->provision($user);

    $accounts = $user->accounts()->get();
    $cash = $accounts->firstWhere('name', 'Cash');

    expect($accounts->where('type', AccountType::Equity)->count())->toBe(1)
        ->and($cash->type)->toBe(AccountType::Asset)
        ->and($cash->currency)->toBe('PEN')
        ->and($accounts->where('type', AccountType::Expense)->count())->toBeGreaterThan(0)
        ->and($accounts->where('type', AccountType::Income)->count())->toBeGreaterThan(0);
});

it('seeds categories without a currency (base by Model A)', function () {
    $user = User::factory()->create(['base_currency' => 'PEN']);

    app(ProvisionNewUserLedger::class)->provision($user);

    $groceries = $user->accounts()->where('name', 'Groceries')->first();
    expect($groceries->type)->toBe(AccountType::Expense)
        ->and($groceries->currency)->toBeNull();
});

it('is idempotent', function () {
    $user = User::factory()->create(['base_currency' => 'PEN']);
    $provision = app(ProvisionNewUserLedger::class);

    $provision->provision($user);
    $count = $user->accounts()->count();
    $provision->provision($user);

    expect($user->accounts()->count())->toBe($count);
});
