<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Product;

use MaxServ\App\Import\DummyJsonProductMapper;
use MaxServ\App\Product\Product;
use MaxServ\App\Tests\Fake\DummyJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Product::class)]
#[UsesClass(DummyJsonProductMapper::class)]
final class ProductTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float, int}>
     */
    public static function discountCases(): iterable
    {
        yield 'regular discount' => [9.99, 10.48, 894];
        yield 'no discount' => [19.99, 0.0, 1999];
        yield 'full discount' => [19.99, 100.0, 0];
        yield 'half cent rounds up' => [19.99, 50.0, 1000];
        yield 'just under half cent rounds down' => [1.0, 0.51, 99];
        yield 'large price' => [36999.99, 19.61, 2974429];
    }

    #[Test]
    #[DataProvider('discountCases')]
    public function discountPriceIsRoundedToWholeCentsWithHalvesUp(
        float $price,
        float $discount,
        int $expectedCents,
    ): void {
        $product = self::product(['price' => $price, 'discountPercentage' => $discount]);

        $discountedCents = $product->discountedPriceInCents();

        self::assertSame($expectedCents, $discountedCents);
    }

    #[Test]
    public function productWithZeroDiscountHasNoDiscount(): void
    {
        $product = self::product(['discountPercentage' => 0]);

        self::assertFalse($product->hasDiscount());
    }

    #[Test]
    public function productWithSmallDiscountHasDiscount(): void
    {
        $product = self::product(['discountPercentage' => 0.04]);

        self::assertTrue($product->hasDiscount());
    }

    #[Test]
    public function displayPricesAreInWholeUnits(): void
    {
        $product = self::product(['price' => 9.99, 'discountPercentage' => 10.48]);

        self::assertSame(9.99, $product->price());
        self::assertSame(8.94, $product->discountedPrice());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function product(array $overrides): Product
    {
        return new DummyJsonProductMapper()->map(DummyJson::product(1, $overrides));
    }
}
