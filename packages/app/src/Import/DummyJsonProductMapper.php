<?php

declare(strict_types=1);

namespace MaxServ\App\Import;

use DateTimeImmutable;
use DateTimeZone;
use MaxServ\App\Product\AvailabilityStatus;
use MaxServ\App\Product\Dimensions;
use MaxServ\App\Product\Product;
use RoundingMode;
use Uri\Rfc3986\Uri;

/**
 * Validates a raw dummyjson.com product and turns it into a Product.
 */
final readonly class DummyJsonProductMapper
{
    private const int MAX_UNSIGNED_INT = 4_294_967_295;
    private const float MAX_DECIMAL = 99_999_999.99;
    private const float MAX_PERCENTAGE = 100.0;
    private const float MAX_RATING = 5.0;
    private const int MAX_TEXT_LENGTH = 255;
    private const int MAX_CATEGORY_LENGTH = 100;
    private const int MAX_CODE_LENGTH = 64;
    private const int MAX_DESCRIPTION_BYTES = 65_535;
    private const int MAX_URL_LENGTH = 2048;
    private const int MAX_LIST_ITEMS = 20;
    private const int MAX_TAG_LENGTH = 100;
    private const int CENTS_PER_UNIT = 100;
    private const string REQUIRED_URL_SCHEME = 'https';
    private const string DATE_FORMAT_WITH_MILLISECONDS = '!Y-m-d\TH:i:s.vP';
    private const string DATE_FORMAT_WITHOUT_MILLISECONDS = '!Y-m-d\TH:i:sP';
    private const string TIME_ZONE = 'UTC';
    private const int MIN_STORABLE_YEAR = 1000;
    private const int MAX_STORABLE_YEAR = 9999;

    /**
     * Validates a raw dummyjson.com product and turns it into a Product.
     *
     * @param mixed $raw The decoded product from dummyjson.com.
     *
     * @return Product The product.
     * @throws InvalidProductData The product is not an object, or a field is missing, has the wrong type, or is out of range.
     */
    public function map(mixed $raw): Product
    {
        if (!is_array($raw)) {
            throw new InvalidProductData('product must be an object.');
        }

        $id = $this->integer($raw, 'id', 1, self::MAX_UNSIGNED_INT);

        try {
            return $this->toProduct($id, $raw);
        } catch (InvalidProductData $exception) {
            throw new InvalidProductData("Product $id: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * @param array<mixed> $raw
     */
    private function toProduct(int $id, array $raw): Product
    {
        $dimensions = $this->object($raw, 'dimensions');
        $meta = $this->object($raw, 'meta');

        return new Product(
            id: $id,
            title: $this->text($raw, 'title', self::MAX_TEXT_LENGTH),
            description: $this->description($raw),
            category: $this->text($raw, 'category', self::MAX_CATEGORY_LENGTH),
            priceInCents: $this->toCents($this->number($raw, 'price', 0.0, self::MAX_DECIMAL)),
            discountPercentage: $this->number($raw, 'discountPercentage', 0.0, self::MAX_PERCENTAGE),
            rating: $this->number($raw, 'rating', 0.0, self::MAX_RATING),
            stock: $this->integer($raw, 'stock', 0, self::MAX_UNSIGNED_INT),
            brand: $this->optionalText($raw, 'brand', self::MAX_TEXT_LENGTH),
            sku: $this->text($raw, 'sku', self::MAX_CODE_LENGTH),
            weight: $this->number($raw, 'weight', 0.0, self::MAX_DECIMAL),
            dimensions: new Dimensions(
                $this->number($dimensions, 'width', 0.0, self::MAX_DECIMAL, 'dimensions.'),
                $this->number($dimensions, 'height', 0.0, self::MAX_DECIMAL, 'dimensions.'),
                $this->number($dimensions, 'depth', 0.0, self::MAX_DECIMAL, 'dimensions.'),
            ),
            warrantyInformation: $this->text($raw, 'warrantyInformation', self::MAX_TEXT_LENGTH),
            shippingInformation: $this->text($raw, 'shippingInformation', self::MAX_TEXT_LENGTH),
            availabilityStatus: $this->availabilityStatus($raw),
            returnPolicy: $this->text($raw, 'returnPolicy', self::MAX_TEXT_LENGTH),
            minimumOrderQuantity: $this->integer($raw, 'minimumOrderQuantity', 0, self::MAX_UNSIGNED_INT),
            barcode: $this->text($meta, 'barcode', self::MAX_CODE_LENGTH, 'meta.'),
            qrCodeUrl: $this->url($this->text($meta, 'qrCode', self::MAX_URL_LENGTH, 'meta.'), 'meta.qrCode'),
            thumbnailUrl: $this->url($this->text($raw, 'thumbnail', self::MAX_URL_LENGTH), 'thumbnail'),
            sourceCreatedAt: $this->date($meta, 'createdAt'),
            sourceUpdatedAt: $this->date($meta, 'updatedAt'),
            tags: $this->tags($raw),
            imageUrls: $this->imageUrls($raw),
        );
    }

    /**
     * @param array<mixed> $raw
     */
    private function integer(array $raw, string $field, int $min, int $max, string $prefix = ''): int
    {
        $value = $raw[$field] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidProductData("$prefix$field must be a whole number from $min to $max.");
        }

        return $value;
    }

    /**
     * @param array<mixed> $raw
     */
    private function number(array $raw, string $field, float $min, float $max, string $prefix = ''): float
    {
        $value = $raw[$field] ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) {
            throw new InvalidProductData("$prefix$field must be a number from $min to $max.");
        }

        return (float) $value;
    }

    /**
     * @param array<mixed> $raw
     */
    private function text(array $raw, string $field, int $maxLength, string $prefix = ''): string
    {
        $value = $raw[$field] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidProductData("$prefix$field must be text of 1 to $maxLength characters.");
        }

        return $value;
    }

    /**
     * @param array<mixed> $raw
     */
    private function optionalText(array $raw, string $field, int $maxLength): ?string
    {
        $value = $raw[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return $this->text($raw, $field, $maxLength);
    }

    /**
     * @param array<mixed> $raw
     */
    private function description(array $raw): string
    {
        $value = $raw['description'] ?? null;
        if (!is_string($value) || strlen($value) > self::MAX_DESCRIPTION_BYTES) {
            throw new InvalidProductData('description must be text of at most ' . self::MAX_DESCRIPTION_BYTES . ' bytes.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array<mixed>
     */
    private function object(array $raw, string $field): array
    {
        $value = $raw[$field] ?? null;
        if (!is_array($value)) {
            throw new InvalidProductData("$field must be an object.");
        }

        return $value;
    }

    /**
     * @param array<mixed> $raw
     */
    private function availabilityStatus(array $raw): AvailabilityStatus
    {
        $value = $raw['availabilityStatus'] ?? null;
        $status = is_string($value) ? AvailabilityStatus::tryFrom($value) : null;
        if ($status === null) {
            throw new InvalidProductData('availabilityStatus is not a known availability status.');
        }

        return $status;
    }

    private function url(string $value, string $field): string
    {
        $uri = Uri::parse($value);
        if ($uri === null || $uri->getScheme() !== self::REQUIRED_URL_SCHEME || ($uri->getHost() ?? '') === '') {
            throw new InvalidProductData("$field must be an https URL with a host.");
        }

        return $value;
    }

    /**
     * @param array<mixed> $meta
     */
    private function date(array $meta, string $field): DateTimeImmutable
    {
        $value = $meta[$field] ?? null;
        $date = is_string($value) ? $this->parseIsoDate($value) : null;
        if ($date === null) {
            throw new InvalidProductData("meta.$field must be an ISO 8601 date and time.");
        }

        $utcDate = $date->setTimezone(new DateTimeZone(self::TIME_ZONE));
        $year = (int) $utcDate->format('Y');
        if ($year < self::MIN_STORABLE_YEAR || $year > self::MAX_STORABLE_YEAR) {
            throw new InvalidProductData(
                "meta.$field must be in the years " . self::MIN_STORABLE_YEAR . ' to ' . self::MAX_STORABLE_YEAR . ' in UTC.'
            );
        }

        return $utcDate;
    }

    private function parseIsoDate(string $value): ?DateTimeImmutable
    {
        foreach ([self::DATE_FORMAT_WITH_MILLISECONDS, self::DATE_FORMAT_WITHOUT_MILLISECONDS] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<string>
     */
    private function tags(array $raw): array
    {
        $tags = $this->stringList($raw, 'tags');
        if (array_any($tags, static fn (string $tag): bool => trim($tag) === '' || mb_strlen(trim($tag)) > self::MAX_TAG_LENGTH)) {
            throw new InvalidProductData('tags must each be text of 1 to ' . self::MAX_TAG_LENGTH . ' characters.');
        }

        return $tags
            |> (static fn (array $values): array => array_map(trim(...), $values))
            |> array_unique(...)
            |> array_values(...);
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<string>
     */
    private function imageUrls(array $raw): array
    {
        $images = $this->stringList($raw, 'images');
        foreach ($images as $image) {
            if (mb_strlen($image) > self::MAX_URL_LENGTH) {
                throw new InvalidProductData('images must each be at most ' . self::MAX_URL_LENGTH . ' characters.');
            }
            $this->url($image, 'images');
        }

        return $images;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<string>
     */
    private function stringList(array $raw, string $field): array
    {
        $value = $raw[$field] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_LIST_ITEMS
            || !array_all($value, static fn (mixed $item): bool => is_string($item))) {
            throw new InvalidProductData("$field must be a list of at most " . self::MAX_LIST_ITEMS . ' texts.');
        }

        return $value;
    }

    private function toCents(float $price): int
    {
        return (int) round($price * self::CENTS_PER_UNIT, 0, RoundingMode::HalfAwayFromZero);
    }
}
