<?php

namespace App\Support;

/**
 * The curated set of currencies the app supports for base currency and accounts.
 *
 * brick/money knows every ISO 4217 currency, but offering all of them is noise; this
 * is the bounded list the UI presents and validation accepts.
 */
final class Currencies
{
    /**
     * @var array<string, string> ISO code => display name
     */
    private const SUPPORTED = [
        'PEN' => 'Peruvian Sol',
        'USD' => 'US Dollar',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'JPY' => 'Japanese Yen',
        'BRL' => 'Brazilian Real',
        'CLP' => 'Chilean Peso',
        'COP' => 'Colombian Peso',
        'MXN' => 'Mexican Peso',
        'ARS' => 'Argentine Peso',
        'CAD' => 'Canadian Dollar',
        'AUD' => 'Australian Dollar',
        'CHF' => 'Swiss Franc',
        'CNY' => 'Chinese Yuan',
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::SUPPORTED);
    }

    public static function isSupported(string $code): bool
    {
        return isset(self::SUPPORTED[strtoupper($code)]);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $code, string $name): array => ['code' => $code, 'name' => $name],
            array_keys(self::SUPPORTED),
            array_values(self::SUPPORTED),
        );
    }
}
