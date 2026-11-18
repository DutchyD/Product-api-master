<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Import;

use MaxServ\App\Import\DummyJsonProductMapper;
use MaxServ\App\Import\InvalidProductData;
use MaxServ\App\Product\AvailabilityStatus;
use MaxServ\App\Product\Dimensions;
use MaxServ\App\Product\Product;
use MaxServ\App\Tests\Fake\DummyJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DummyJsonProductMapper::class)]
#[CoversClass(InvalidProductData::class)]
#[UsesClass(Product::class)]
#[UsesClass(Dimensions::class)]
final class DummyJsonProductMapperTest extends TestCase
{
    #[Test]
    public function valid_product_maps_every_field(): void
    {
        $product = new DummyJsonProductMapper()->map(DummyJson::product(7));

        self::assertSame(7, $product->id);
        self::assertSame('Example product 7', $product->title);
        self::assertSame('An example product used in tests.', $product->description);
        self::assertSame('beauty', $product->category);
        self::assertSame(999, $product->priceInCents);
        self::assertSame(10.48, $product->discountPercentage);
        self::assertSame(2.56, $product->rating);
        self::assertSame(99, $product->stock);
        self::assertSame('Example Brand', $product->brand);
        self::assertSame('SKU-7', $product->sku);
        self::assertSame(4.0, $product->weight);
        self::assertEquals(new Dimensions(15.14, 13.08, 22.99), $product->dimensions);
        self::assertSame('1 week warranty', $product->warrantyInformation);
        self::assertSame('Ships in 3-5 business days', $product->shippingInformation);
        self::assertSame(AvailabilityStatus::InStock, $product->availabilityStatus);
        self::assertSame('No return policy', $product->returnPolicy);
        self::assertSame(48, $product->minimumOrderQuantity);
        self::assertSame('5784719087687', $product->barcode);
        self::assertSame('https://cdn.example.com/qr-code.png', $product->qrCodeUrl);
        self::assertSame('https://cdn.example.com/products/7/thumbnail.webp', $product->thumbnailUrl);
        self::assertSame('2025-10-09T14:47:01.588+00:00', $product->sourceCreatedAt->format('Y-m-d\TH:i:s.vP'));
        self::assertSame('2026-05-23T11:27:41.868+00:00', $product->sourceUpdatedAt->format('Y-m-d\TH:i:s.vP'));
        self::assertSame(['beauty', 'mascara'], $product->tags);
        self::assertSame(
            ['https://cdn.example.com/products/7/1.webp', 'https://cdn.example.com/products/7/2.webp'],
            $product->imageUrls,
        );
    }

    #[Test]
    public function missing_brand_maps_to_null(): void
    {
        $raw = DummyJson::product();
        unset($raw['brand']);

        $product = new DummyJsonProductMapper()->map($raw);

        self::assertNull($product->brand);
    }

    #[Test]
    public function empty_brand_maps_to_null(): void
    {
        $product = new DummyJsonProductMapper()->map(DummyJson::product(overrides: ['brand' => '']));

        self::assertNull($product->brand);
    }

    #[Test]
    public function dates_with_an_offset_are_converted_to_utc(): void
    {
        $raw = DummyJson::product();
        $raw['meta']['createdAt'] = '2025-10-09T16:47:01+02:00';

        $product = new DummyJsonProductMapper()->map($raw);

        self::assertSame('2025-10-09 14:47:01 UTC', $product->sourceCreatedAt->format('Y-m-d H:i:s T'));
    }

    #[Test]
    public function dates_at_the_edges_of_the_storable_years_are_accepted(): void
    {
        $raw = DummyJson::product();
        $raw['meta']['createdAt'] = '1000-01-01T00:00:00Z';
        $raw['meta']['updatedAt'] = '9999-12-31T23:59:59.999Z';

        $product = new DummyJsonProductMapper()->map($raw);

        self::assertSame('1000-01-01', $product->sourceCreatedAt->format('Y-m-d'));
        self::assertSame('9999-12-31', $product->sourceUpdatedAt->format('Y-m-d'));
    }

    #[Test]
    public function tags_are_trimmed_and_duplicates_removed_in_order(): void
    {
        $product = new DummyJsonProductMapper()->map(DummyJson::product(overrides: ['tags' => [' beauty', 'mascara', 'beauty ']]));

        self::assertSame(['beauty', 'mascara'], $product->tags);
    }

    #[Test]
    public function price_is_converted_to_cents_without_float_errors(): void
    {
        $product = new DummyJsonProductMapper()->map(DummyJson::product(overrides: ['price' => 0.29]));

        self::assertSame(29, $product->priceInCents);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidFields(): iterable
    {
        yield 'id missing' => [['id' => null]];
        yield 'id zero' => [['id' => 0]];
        yield 'id as text' => [['id' => '1']];
        yield 'id too large' => [['id' => 4_294_967_296]];
        yield 'title empty' => [['title' => '  ']];
        yield 'title too long' => [['title' => str_repeat('a', 256)]];
        yield 'title not text' => [['title' => 12]];
        yield 'description too long' => [['description' => str_repeat('a', 65_536)]];
        yield 'category too long' => [['category' => str_repeat('a', 101)]];
        yield 'price negative' => [['price' => -0.01]];
        yield 'price as text' => [['price' => '9.99']];
        yield 'price too large' => [['price' => 100_000_000]];
        yield 'discount above 100' => [['discountPercentage' => 100.01]];
        yield 'rating above 5' => [['rating' => 5.1]];
        yield 'stock negative' => [['stock' => -1]];
        yield 'stock decimal' => [['stock' => 1.5]];
        yield 'brand too long' => [['brand' => str_repeat('a', 256)]];
        yield 'brand not text' => [['brand' => ['Brand']]];
        yield 'sku too long' => [['sku' => str_repeat('a', 65)]];
        yield 'weight negative' => [['weight' => -1]];
        yield 'dimensions missing' => [['dimensions' => null]];
        yield 'dimension negative' => [['dimensions' => ['width' => -1, 'height' => 1, 'depth' => 1]]];
        yield 'warranty missing' => [['warrantyInformation' => null]];
        yield 'unknown availability' => [['availabilityStatus' => 'Discontinued']];
        yield 'availability not text' => [['availabilityStatus' => 1]];
        yield 'return policy too long' => [['returnPolicy' => str_repeat('a', 256)]];
        yield 'minimum order negative' => [['minimumOrderQuantity' => -1]];
        yield 'meta missing' => [['meta' => null]];
        yield 'thumbnail over http' => [['thumbnail' => 'http://cdn.example.com/thumbnail.webp']];
        yield 'thumbnail javascript' => [['thumbnail' => 'javascript:alert(1)']];
        yield 'thumbnail without host' => [['thumbnail' => 'https:///thumbnail.webp']];
        yield 'thumbnail not a URL' => [['thumbnail' => 'https://exa mple.com/a b']];
        yield 'thumbnail too long' => [['thumbnail' => 'https://cdn.example.com/' . str_repeat('a', 2048)]];
        yield 'image over http' => [['images' => ['http://cdn.example.com/1.webp']]];
        yield 'images not a list' => [['images' => ['first' => 'https://cdn.example.com/1.webp']]];
        yield 'too many images' => [['images' => array_fill(0, 21, 'https://cdn.example.com/1.webp')]];
        yield 'tags not a list' => [['tags' => 'beauty']];
        yield 'tag not text' => [['tags' => [1]]];
        yield 'tag empty' => [['tags' => ['  ']]];
        yield 'tag too long' => [['tags' => [str_repeat('a', 101)]]];
        yield 'too many tags' => [['tags' => array_map(static fn (int $i): string => "tag$i", range(1, 21))]];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[Test]
    #[DataProvider('invalidFields')]
    public function product_with_an_invalid_field_is_rejected(array $overrides): void
    {
        $this->expectException(InvalidProductData::class);

        new DummyJsonProductMapper()->map(DummyJson::product(1, $overrides));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidMetaFields(): iterable
    {
        yield 'created date not ISO 8601' => ['createdAt', 'tomorrow'];
        yield 'created date impossible' => ['createdAt', '2025-13-45T10:00:00.000Z'];
        yield 'updated date missing' => ['updatedAt', null];
        yield 'barcode too long' => ['barcode', str_repeat('1', 65)];
        yield 'QR code over http' => ['qrCode', 'http://cdn.example.com/qr.png'];
        yield 'date past year 9999 in UTC' => ['createdAt', '9999-12-31T23:30:00-01:00'];
        yield 'date before year 1000' => ['updatedAt', '0999-12-31T23:59:59Z'];
    }

    #[Test]
    #[DataProvider('invalidMetaFields')]
    public function product_with_an_invalid_meta_field_is_rejected(string $field, mixed $value): void
    {
        $raw = DummyJson::product();
        $raw['meta'][$field] = $value;

        $this->expectException(InvalidProductData::class);

        new DummyJsonProductMapper()->map($raw);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonObjects(): iterable
    {
        yield 'null' => [null];
        yield 'number' => [7];
        yield 'text' => ['product'];
    }

    #[Test]
    #[DataProvider('nonObjects')]
    public function entry_that_is_not_an_object_is_rejected(mixed $raw): void
    {
        $this->expectException(InvalidProductData::class);
        $this->expectExceptionMessage('product must be an object.');

        new DummyJsonProductMapper()->map($raw);
    }

    #[Test]
    public function rejection_names_the_product_and_the_field(): void
    {
        $this->expectExceptionMessage('Product 3: title must be text of 1 to 255 characters.');

        new DummyJsonProductMapper()->map(DummyJson::product(3, ['title' => '']));
    }
}
