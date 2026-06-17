<?php

use App\Enums\AccountType;

it('flips display sign only for liability, income and equity', function (AccountType $type, int $sign) {
    expect($type->displaySign())->toBe($sign);
})->with([
    'asset shows raw' => [AccountType::Asset, 1],
    'expense shows raw' => [AccountType::Expense, 1],
    'liability flips' => [AccountType::Liability, -1],
    'income flips' => [AccountType::Income, -1],
    'equity flips' => [AccountType::Equity, -1],
]);

it('splits the world into My Accounts and Categories', function () {
    expect(AccountType::Asset->isMyAccount())->toBeTrue()
        ->and(AccountType::Liability->isMyAccount())->toBeTrue()
        ->and(AccountType::Income->isMyAccount())->toBeFalse()
        ->and(AccountType::Expense->isMyAccount())->toBeFalse()
        ->and(AccountType::Equity->isMyAccount())->toBeFalse();

    expect(AccountType::Income->isCategory())->toBeTrue()
        ->and(AccountType::Expense->isCategory())->toBeTrue()
        ->and(AccountType::Asset->isCategory())->toBeFalse();
});

it('uses a native currency only for asset and liability (Model A)', function (AccountType $type, bool $native) {
    expect($type->usesNativeCurrency())->toBe($native);
})->with([
    'asset native' => [AccountType::Asset, true],
    'liability native' => [AccountType::Liability, true],
    'income base' => [AccountType::Income, false],
    'expense base' => [AccountType::Expense, false],
    'equity base' => [AccountType::Equity, false],
]);
