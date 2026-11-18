<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Fake;

use GuzzleHttp\Psr7\Response;

/**
 * Builds dummyjson.com payloads and responses for tests.
 */
final class DummyJson
{
    /**
     * Returns a valid raw product, shaped like a dummyjson.com product.
     *
     * @param int $id The product id.
     * @param array<string, mixed> $overrides Fields to replace or add.
     *
     * @return array<string, mixed> The raw product.
     */
    public static function product(int $id = 1, array $overrides = []): array
    {
        return [
            'id' => $id,
            'title' => "Example product $id",
            'description' => 'An example product used in tests.',
            'category' => 'beauty',
            'price' => 9.99,
            'discountPercentage' => 10.48,
            'rating' => 2.56,
            'stock' => 99,
            'tags' => ['beauty', 'mascara'],
            'brand' => 'Example Brand',
            'sku' => "SKU-$id",
            'weight' => 4,
            'dimensions' => ['width' => 15.14, 'height' => 13.08, 'depth' => 22.99],
            'warrantyInformation' => '1 week warranty',
            'shippingInformation' => 'Ships in 3-5 business days',
            'availabilityStatus' => 'In Stock',
            'returnPolicy' => 'No return policy',
            'minimumOrderQuantity' => 48,
            'meta' => [
                'createdAt' => '2025-10-09T14:47:01.588Z',
                'updatedAt' => '2026-05-23T11:27:41.868Z',
                'barcode' => '5784719087687',
                'qrCode' => 'https://cdn.example.com/qr-code.png',
            ],
            'images' => ["https://cdn.example.com/products/$id/1.webp", "https://cdn.example.com/products/$id/2.webp"],
            'thumbnail' => "https://cdn.example.com/products/$id/thumbnail.webp",
            ...$overrides,
        ];
    }

    /**
     * Returns valid raw products with consecutive ids.
     *
     * @param int $firstId The id of the first product.
     * @param int $count The number of products.
     *
     * @return list<array<string, mixed>> The raw products.
     */
    public static function products(int $firstId, int $count): array
    {
        return array_map(self::product(...), range($firstId, $firstId + $count - 1));
    }

    /**
     * Returns a 200 response with a product page.
     *
     * @param int $total The total number of products at the source.
     * @param list<array<string, mixed>> $products The products on the page.
     *
     * @return Response The response.
     */
    public static function pageResponse(int $total, array $products): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(
            ['products' => $products, 'total' => $total, 'skip' => 0, 'limit' => count($products)],
            JSON_THROW_ON_ERROR,
        ));
    }
}
