<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

use DateTimeImmutable;
use RoundingMode;

/**
 * A product from dummyjson.com, identified by its dummyjson.com id.
 */
final readonly class Product
{
    private const int CENTS_PER_UNIT = 100;
    private const int BASIS_POINTS_PER_PERCENT = 100;
    private const int FULL_BASIS_POINTS = 10_000;

    /**
     * Creates the product.
     *
     * @param int $id The dummyjson.com id.
     * @param string $title The title.
     * @param string $description The description.
     * @param string $category The category.
     * @param int $priceInCents The normal price in cents.
     * @param float $discountPercentage The discount, from 0 to 100.
     * @param float $rating The rating, from 0 to 5.
     * @param int $stock The number of items in stock.
     * @param string|null $brand The brand, or null when the product has none.
     * @param string $sku The stock keeping unit.
     * @param float $weight The weight.
     * @param Dimensions $dimensions The width, height and depth.
     * @param string $warrantyInformation The warranty terms.
     * @param string $shippingInformation The shipping terms.
     * @param AvailabilityStatus $availabilityStatus Whether the product can be ordered from stock.
     * @param string $returnPolicy The return policy.
     * @param int $minimumOrderQuantity The smallest quantity that can be ordered.
     * @param string $barcode The barcode.
     * @param string $qrCodeUrl The https URL of the QR code image.
     * @param string $thumbnailUrl The https URL of the thumbnail image.
     * @param DateTimeImmutable $sourceCreatedAt When dummyjson.com created the product, in UTC.
     * @param DateTimeImmutable $sourceUpdatedAt When dummyjson.com last updated the product, in UTC.
     * @param list<string> $tags The tags, in order.
     * @param list<string> $imageUrls The https URLs of the images, in order.
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $description,
        public string $category,
        public int $priceInCents,
        public float $discountPercentage,
        public float $rating,
        public int $stock,
        public ?string $brand,
        public string $sku,
        public float $weight,
        public Dimensions $dimensions,
        public string $warrantyInformation,
        public string $shippingInformation,
        public AvailabilityStatus $availabilityStatus,
        public string $returnPolicy,
        public int $minimumOrderQuantity,
        public string $barcode,
        public string $qrCodeUrl,
        public string $thumbnailUrl,
        public DateTimeImmutable $sourceCreatedAt,
        public DateTimeImmutable $sourceUpdatedAt,
        public array $tags,
        public array $imageUrls,
    ) {
    }

    /**
     * Tells whether the product has a discount.
     *
     * @return bool True when the discount percentage is above zero.
     */
    #[\NoDiscard]
    public function hasDiscount(): bool
    {
        return $this->discountPercentage > 0;
    }

    /**
     * Calculates the price after the discount, rounded to whole cents with halves rounded up.
     *
     * @return int The discount price in cents.
     */
    #[\NoDiscard]
    public function discountedPriceInCents(): int
    {
        $discountBasisPoints = (int) round($this->discountPercentage * self::BASIS_POINTS_PER_PERCENT, 0, RoundingMode::HalfAwayFromZero);
        $remainingBasisPoints = self::FULL_BASIS_POINTS - $discountBasisPoints;
        $halfUnit = intdiv(self::FULL_BASIS_POINTS, 2);

        return intdiv($this->priceInCents * $remainingBasisPoints + $halfUnit, self::FULL_BASIS_POINTS);
    }

    /**
     * Returns the normal price in whole currency units, for display.
     *
     * @return float The price, for example 9.99.
     */
    #[\NoDiscard]
    public function price(): float
    {
        return $this->priceInCents / self::CENTS_PER_UNIT;
    }

    /**
     * Returns the discount price in whole currency units, for display.
     *
     * @return float The discount price, for example 8.94.
     */
    #[\NoDiscard]
    public function discountedPrice(): float
    {
        return $this->discountedPriceInCents() / self::CENTS_PER_UNIT;
    }
}
