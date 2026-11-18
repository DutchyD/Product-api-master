<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Product;

use InvalidArgumentException;
use MaxServ\App\Product\DecimalColumn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DecimalColumn::class)]
final class DecimalColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function centsAndDecimals(): iterable
    {
        yield 'zero' => [0, '0.00'];
        yield 'cents only with leading zero' => [5, '0.05'];
        yield 'leading zero in cents' => [905, '9.05'];
        yield 'regular price' => [999, '9.99'];
        yield 'whole amount' => [1000, '10.00'];
        yield 'column maximum' => [9_999_999_999, '99999999.99'];
    }

    #[Test]
    #[DataProvider('centsAndDecimals')]
    public function cents_are_formatted_with_two_decimals(int $cents, string $decimal): void
    {
        self::assertSame($decimal, DecimalColumn::fromCents($cents));
    }

    #[Test]
    #[DataProvider('centsAndDecimals')]
    public function decimal_is_read_back_as_the_same_cents(int $cents, string $decimal): void
    {
        self::assertSame($cents, DecimalColumn::toCents($decimal));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function shortDecimals(): iterable
    {
        yield 'one decimal' => ['9.5', 950];
        yield 'no decimals' => ['10', 1000];
    }

    #[Test]
    #[DataProvider('shortDecimals')]
    public function decimal_with_fewer_than_two_decimals_is_read_as_cents(string $decimal, int $expectedCents): void
    {
        self::assertSame($expectedCents, DecimalColumn::toCents($decimal));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDecimals(): iterable
    {
        yield 'negative' => ['-1.00'];
        yield 'three decimals' => ['1.005'];
        yield 'empty' => [''];
        yield 'not a number' => ['abc'];
        yield 'missing units' => ['.50'];
        yield 'exponent' => ['1e3'];
    }

    #[Test]
    #[DataProvider('invalidDecimals')]
    public function invalid_decimal_is_rejected(string $decimal): void
    {
        $this->expectException(InvalidArgumentException::class);

        DecimalColumn::toCents($decimal);
    }

    #[Test]
    public function negative_cents_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DecimalColumn::fromCents(-1);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function floatsAndDecimals(): iterable
    {
        yield 'whole number' => [4.0, '4.00'];
        yield 'two decimals' => [10.48, '10.48'];
        yield 'rounded to two decimals' => [15.146, '15.15'];
        yield 'no thousands separator' => [1234.5, '1234.50'];
    }

    #[Test]
    #[DataProvider('floatsAndDecimals')]
    public function number_is_formatted_with_two_decimals_and_no_separators(float $value, string $decimal): void
    {
        self::assertSame($decimal, DecimalColumn::fromFloat($value));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidFloats(): iterable
    {
        yield 'negative' => [-0.01];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }

    #[Test]
    #[DataProvider('invalidFloats')]
    public function invalid_number_is_rejected(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        DecimalColumn::fromFloat($value);
    }
}
