<?php

namespace App\Support;

use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money as BrickMoney;

/**
 * Thin, immutable money value object for the app.
 *
 * The rest of the codebase deals only in signed integer minor units plus an ISO
 * currency code; this wrapper is the single seam onto brick/money, so we keep the
 * option to swap the underlying library later.
 */
final readonly class Money
{
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {}

    /**
     * Build from a signed integer number of minor units (the canonical storage form).
     *
     * @throws UnknownCurrencyException
     */
    public static function ofMinor(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, Currency::of(strtoupper($currency))->getCurrencyCode());
    }

    /**
     * Parse a human-entered amount (e.g. "50", "50.5") into minor units for the currency.
     *
     * @throws UnknownCurrencyException
     */
    public static function parse(string|int|float $amount, string $currency): self
    {
        $brick = BrickMoney::of((string) $amount, strtoupper($currency), roundingMode: RoundingMode::HalfUp);

        return new self(
            $brick->getMinorAmount()->toInt(),
            $brick->getCurrency()->getCurrencyCode(),
        );
    }

    /**
     * Number of fraction digits (the exponent) for a currency: USD=2, JPY=0, KWD=3.
     *
     * @throws UnknownCurrencyException
     */
    public static function fractionDigits(string $currency): int
    {
        return Currency::of($currency)->getDefaultFractionDigits();
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function negate(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    /**
     * Format for display in the given locale, e.g. "S/50.00", "¥50", "$12.34".
     */
    public function format(string $locale = 'en'): string
    {
        return BrickMoney::ofMinor($this->minorUnits, $this->currency)->formatTo($locale);
    }
}
