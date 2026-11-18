<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

use InvalidArgumentException;

/**
 * Converts values to and from the non-negative DECIMAL(n, 2) columns of the product tables without float errors.
 */
final class DecimalColumn
{
    private const int CENTS_PER_UNIT = 100;
    private const int DECIMAL_PLACES = 2;
    private const string DECIMAL_PATTERN = '/^(\d+)(?:\.(\d{1,2}))?$/';

    /**
     * Formats an amount in cents as a DECIMAL column value.
     *
     * @param int $cents The amount in cents, from 0.
     *
     * @return string The value, for example "9.05" for 905 cents.
     * @throws InvalidArgumentException The amount is negative.
     */
    public static function fromCents(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('The amount in cents must not be negative.');
        }

        return sprintf('%d.%02d', intdiv($cents, self::CENTS_PER_UNIT), $cents % self::CENTS_PER_UNIT);
    }

    /**
     * Reads a DECIMAL column value as an amount in cents.
     *
     * @param string $value The value, with at most two decimals, for example "9.05", "9.5" or "10".
     *
     * @return int The amount in cents.
     * @throws InvalidArgumentException The value is not a non-negative decimal with at most two decimals.
     */
    public static function toCents(string $value): int
    {
        if (preg_match(self::DECIMAL_PATTERN, $value, $matches) !== 1) {
            throw new InvalidArgumentException("\"$value\" is not a non-negative decimal with at most two decimals.");
        }

        $fraction = str_pad($matches[2] ?? '', self::DECIMAL_PLACES, '0');

        return (int) $matches[1] * self::CENTS_PER_UNIT + (int) $fraction;
    }

    /**
     * Formats a number as a DECIMAL column value, rounded to two decimals.
     *
     * @param float $value The number, from 0.
     *
     * @return string The value, for example "4.00" for 4.
     * @throws InvalidArgumentException The number is negative or not finite.
     */
    public static function fromFloat(float $value): string
    {
        if ($value < 0 || !is_finite($value)) {
            throw new InvalidArgumentException('The number must be finite and not negative.');
        }

        return number_format($value, self::DECIMAL_PLACES, '.', '');
    }
}
