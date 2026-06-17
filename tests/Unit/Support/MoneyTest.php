<?php

use App\Support\Money;
use Brick\Money\Exception\UnknownCurrencyException;

it('parses whole amounts into minor units per currency exponent', function (string $currency, string $input, int $expected) {
    expect(Money::parse($input, $currency)->minorUnits)->toBe($expected);
})->with([
    'USD 2 digits' => ['USD', '50', 5000],
    'USD decimals' => ['USD', '50.5', 5050],
    'USD cents' => ['USD', '12.34', 1234],
    'JPY 0 digits' => ['JPY', '50', 50],
    'KWD 3 digits' => ['KWD', '50', 50000],
    'KWD fine' => ['KWD', '1.234', 1234],
    'PEN base' => ['PEN', '50', 5000],
]);

it('reports the fraction digits (exponent) for a currency', function () {
    expect(Money::fractionDigits('USD'))->toBe(2)
        ->and(Money::fractionDigits('JPY'))->toBe(0)
        ->and(Money::fractionDigits('KWD'))->toBe(3);
});

it('builds from minor units and normalizes the currency code', function () {
    $money = Money::ofMinor(5000, 'usd');

    expect($money->minorUnits)->toBe(5000)
        ->and($money->currency)->toBe('USD');
});

it('keeps the sign of minor units', function () {
    expect(Money::ofMinor(-450, 'PEN')->minorUnits)->toBe(-450);
});

it('negates and absolutizes without losing currency', function () {
    $owed = Money::ofMinor(-450, 'PEN');

    expect($owed->negate()->minorUnits)->toBe(450)
        ->and($owed->abs()->minorUnits)->toBe(450)
        ->and($owed->abs()->currency)->toBe('PEN');
});

it('detects zero', function () {
    expect(Money::ofMinor(0, 'USD')->isZero())->toBeTrue()
        ->and(Money::ofMinor(1, 'USD')->isZero())->toBeFalse();
});

it('formats minor units for display respecting the exponent', function () {
    expect(Money::ofMinor(1234, 'USD')->format())->toContain('12.34')
        ->and(Money::ofMinor(50, 'JPY')->format())->toContain('50');
});

it('rejects unknown currency codes', function () {
    Money::ofMinor(100, 'XYZ');
})->throws(UnknownCurrencyException::class);
