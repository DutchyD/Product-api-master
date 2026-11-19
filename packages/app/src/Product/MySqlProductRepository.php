<?php

declare(strict_types=1);

namespace MaxServ\App\Product;

use DateTimeImmutable;
use DateTimeZone;
use MaxServ\Core\Database\Connection;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Stores products in the MySQL tables products, product_tags and product_images.
 */
final readonly class MySqlProductRepository implements ProductRepository
{
    private const string DATE_TIME_FORMAT = 'Y-m-d H:i:s.v';
    private const string TIME_ZONE = 'UTC';

    private const string PRODUCT_COLUMNS = 'id, title, description, category, price, discount_percentage, rating, '
        . 'stock, brand, sku, weight, width, height, depth, warranty_information, shipping_information, '
        . 'availability_status, return_policy, minimum_order_quantity, barcode, qr_code_url, thumbnail_url, '
        . 'source_created_at, source_updated_at';

    private const string UPSERT_PRODUCT = 'INSERT INTO products (' . self::PRODUCT_COLUMNS . ', imported_at) VALUES ('
        . ':id, :title, :description, :category, :price, :discount_percentage, :rating, :stock, :brand, :sku, '
        . ':weight, :width, :height, :depth, :warranty_information, :shipping_information, :availability_status, '
        . ':return_policy, :minimum_order_quantity, :barcode, :qr_code_url, :thumbnail_url, :source_created_at, '
        . ':source_updated_at, :imported_at) AS new ON DUPLICATE KEY UPDATE '
        . 'title = new.title, description = new.description, category = new.category, price = new.price, '
        . 'discount_percentage = new.discount_percentage, rating = new.rating, stock = new.stock, '
        . 'brand = new.brand, sku = new.sku, weight = new.weight, width = new.width, height = new.height, '
        . 'depth = new.depth, warranty_information = new.warranty_information, '
        . 'shipping_information = new.shipping_information, availability_status = new.availability_status, '
        . 'return_policy = new.return_policy, minimum_order_quantity = new.minimum_order_quantity, '
        . 'barcode = new.barcode, qr_code_url = new.qr_code_url, thumbnail_url = new.thumbnail_url, '
        . 'source_created_at = new.source_created_at, source_updated_at = new.source_updated_at, '
        . 'imported_at = new.imported_at';

    /**
     * The price after the discount in SQL, rounded half up to whole cents with exact DECIMAL arithmetic.
     */
    private const string DISCOUNTED_PRICE_SQL = 'ROUND(price * (100 - discount_percentage) / 100, 2)';

    /**
     * Compares filter values byte for byte, so matching ignores neither case nor accents.
     */
    private const string EXACT_COLLATION = 'utf8mb4_bin';

    private PDO $pdo;

    /**
     * Creates the repository.
     *
     * @param Connection $connection The database that holds the product tables.
     */
    public function __construct(Connection $connection)
    {
        $this->pdo = $connection->getConnection();
    }

    /**
     * Inserts the products, or updates them with their tags and images when they already exist, in one transaction.
     *
     * @param list<Product> $products The products to store.
     *
     * @return void
     * @throws \PDOException The products could not be stored; none of them are stored.
     */
    #[\Override]
    public function save(array $products): void
    {
        if ($products === []) {
            return;
        }

        $importedAt = $this->now();
        $this->pdo->beginTransaction();

        try {
            $upsertProduct = $this->pdo->prepare(self::UPSERT_PRODUCT);
            $deleteTags = $this->pdo->prepare('DELETE FROM product_tags WHERE product_id = :product_id');
            $insertTag = $this->pdo->prepare(
                'INSERT INTO product_tags (product_id, position, tag) VALUES (:product_id, :position, :value)'
            );
            $deleteImages = $this->pdo->prepare('DELETE FROM product_images WHERE product_id = :product_id');
            $insertImage = $this->pdo->prepare(
                'INSERT INTO product_images (product_id, position, url) VALUES (:product_id, :position, :value)'
            );

            foreach ($products as $product) {
                $upsertProduct->execute([...$this->toRow($product), 'imported_at' => $importedAt]);
                $this->replaceChildren($deleteTags, $insertTag, $product->id, $product->tags);
                $this->replaceChildren($deleteImages, $insertImage, $product->id, $product->imageUrls);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBackQuietly();
            throw $exception;
        }
    }

    /**
     * Finds a product by its id.
     *
     * @param int $id The product id.
     *
     * @return Product|null The product, or null when no product has this id.
     * @throws \PDOException The products could not be read.
     */
    #[\Override]
    public function findById(int $id): ?Product
    {
        $statement = $this->pdo->prepare('SELECT ' . self::PRODUCT_COLUMNS . ' FROM products WHERE id = :id');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return array_first($this->hydrate([$row]));
    }

    /**
     * Finds one page of the products that match the filters, in the requested order; a page past the last page
     * returns the last page.
     *
     * @param ProductListRequest $listRequest The filters, sorting and page to find.
     *
     * @return ProductPage The page.
     * @throws \PDOException The products could not be read.
     */
    #[\Override]
    public function findPage(ProductListRequest $listRequest): ProductPage
    {
        [$where, $filterValues] = $this->filterClause($listRequest);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM products$where");
        $count->execute($filterValues);
        $totalProducts = (int) $count->fetchColumn();

        $pageRequest = $listRequest->pageRequest->clampedTo($totalProducts);
        $statement = $this->pdo->prepare(
            'SELECT ' . self::PRODUCT_COLUMNS . " FROM products$where ORDER BY "
            . $this->orderByClause($listRequest) . ' LIMIT :limit OFFSET :offset'
        );
        foreach ($filterValues as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', $pageRequest->perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $pageRequest->offset(), PDO::PARAM_INT);
        $statement->execute();

        return new ProductPage(
            $this->hydrate($statement->fetchAll()),
            $listRequest->withPageRequest($pageRequest),
            $totalProducts,
        );
    }

    /**
     * Finds every stored category and brand, for the filters of the product list.
     *
     * @return FilterOptions The distinct categories and brands, in alphabetical order.
     * @throws \PDOException The products could not be read.
     */
    #[\Override]
    public function findFilterOptions(): FilterOptions
    {
        return new FilterOptions($this->distinctValues('category'), $this->distinctValues('brand'));
    }

    /**
     * @return array{string, array<string, string>} The WHERE clause (empty without filters) and its bound values.
     */
    private function filterClause(ProductListRequest $listRequest): array
    {
        $conditions = [];
        $values = [];

        if ($listRequest->category !== null) {
            $conditions[] = 'category COLLATE ' . self::EXACT_COLLATION . ' = :category';
            $values['category'] = $listRequest->category;
        }

        if ($listRequest->brand !== null) {
            $conditions[] = 'brand COLLATE ' . self::EXACT_COLLATION . ' = :brand';
            $values['brand'] = $listRequest->brand;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $values];
    }

    /**
     * Returns the values of a column that differ byte for byte, without null, in alphabetical order.
     *
     * @param 'category'|'brand' $column
     *
     * @return list<string>
     */
    private function distinctValues(string $column): array
    {
        $values = $this->pdo->query(
            "SELECT MIN($column) AS value FROM products WHERE $column IS NOT NULL"
            . " GROUP BY $column COLLATE " . self::EXACT_COLLATION
            . ' ORDER BY value, value COLLATE ' . self::EXACT_COLLATION
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map(strval(...), $values);
    }

    /**
     * Builds the ORDER BY keys from fixed SQL only; no request value reaches the SQL text.
     */
    private function orderByClause(ProductListRequest $listRequest): string
    {
        $direction = match ($listRequest->sortDirection) {
            SortDirection::Ascending => 'ASC',
            SortDirection::Descending => 'DESC',
        };

        $sortKeys = match ($listRequest->sortColumn) {
            null => [],
            SortColumn::Title => ["title $direction"],
            SortColumn::Price => [self::DISCOUNTED_PRICE_SQL . " $direction"],
            SortColumn::Brand => ['brand IS NULL ASC', "brand $direction"],
            SortColumn::Category => ["category $direction"],
        };

        return implode(', ', [...$sortKeys, 'id ASC']);
    }

    /**
     * @param list<string> $values
     */
    private function replaceChildren(PDOStatement $delete, PDOStatement $insert, int $productId, array $values): void
    {
        $delete->execute(['product_id' => $productId]);

        foreach ($values as $position => $value) {
            $insert->execute(['product_id' => $productId, 'position' => $position, 'value' => $value]);
        }
    }

    /**
     * @return array<string, string|int|null>
     */
    private function toRow(Product $product): array
    {
        return [
            'id' => $product->id,
            'title' => $product->title,
            'description' => $product->description,
            'category' => $product->category,
            'price' => DecimalColumn::fromCents($product->priceInCents),
            'discount_percentage' => DecimalColumn::fromFloat($product->discountPercentage),
            'rating' => DecimalColumn::fromFloat($product->rating),
            'stock' => $product->stock,
            'brand' => $product->brand,
            'sku' => $product->sku,
            'weight' => DecimalColumn::fromFloat($product->weight),
            'width' => DecimalColumn::fromFloat($product->dimensions->width),
            'height' => DecimalColumn::fromFloat($product->dimensions->height),
            'depth' => DecimalColumn::fromFloat($product->dimensions->depth),
            'warranty_information' => $product->warrantyInformation,
            'shipping_information' => $product->shippingInformation,
            'availability_status' => $product->availabilityStatus->value,
            'return_policy' => $product->returnPolicy,
            'minimum_order_quantity' => $product->minimumOrderQuantity,
            'barcode' => $product->barcode,
            'qr_code_url' => $product->qrCodeUrl,
            'thumbnail_url' => $product->thumbnailUrl,
            'source_created_at' => $this->formatDate($product->sourceCreatedAt),
            'source_updated_at' => $this->formatDate($product->sourceUpdatedAt),
        ];
    }

    /**
     * Turns product rows into products, loading the tags and images of all rows with one query per table.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Product>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $productIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $tags = $this->loadChildren('product_tags', 'tag', $productIds);
        $images = $this->loadChildren('product_images', 'url', $productIds);

        return array_map(
            fn (array $row): Product => $this->toProduct(
                $row,
                $tags[(int) $row['id']] ?? [],
                $images[(int) $row['id']] ?? [],
            ),
            $rows,
        );
    }

    /**
     * @param 'product_tags'|'product_images' $table
     * @param 'tag'|'url' $column
     * @param non-empty-list<int> $productIds
     *
     * @return array<int, list<string>> The values per product id, in position order.
     */
    private function loadChildren(string $table, string $column, array $productIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT product_id, $column FROM $table WHERE product_id IN ($placeholders) ORDER BY product_id, position"
        );
        foreach ($productIds as $index => $productId) {
            $statement->bindValue($index + 1, $productId, PDO::PARAM_INT);
        }
        $statement->execute();

        $valuesByProduct = [];
        foreach ($statement->fetchAll() as $row) {
            $valuesByProduct[(int) $row['product_id']][] = (string) $row[$column];
        }

        return $valuesByProduct;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $tags
     * @param list<string> $imageUrls
     */
    private function toProduct(array $row, array $tags, array $imageUrls): Product
    {
        return new Product(
            id: (int) $row['id'],
            title: (string) $row['title'],
            description: (string) $row['description'],
            category: (string) $row['category'],
            priceInCents: DecimalColumn::toCents((string) $row['price']),
            discountPercentage: (float) $row['discount_percentage'],
            rating: (float) $row['rating'],
            stock: (int) $row['stock'],
            brand: $row['brand'] === null ? null : (string) $row['brand'],
            sku: (string) $row['sku'],
            weight: (float) $row['weight'],
            dimensions: new Dimensions((float) $row['width'], (float) $row['height'], (float) $row['depth']),
            warrantyInformation: (string) $row['warranty_information'],
            shippingInformation: (string) $row['shipping_information'],
            availabilityStatus: AvailabilityStatus::from((string) $row['availability_status']),
            returnPolicy: (string) $row['return_policy'],
            minimumOrderQuantity: (int) $row['minimum_order_quantity'],
            barcode: (string) $row['barcode'],
            qrCodeUrl: (string) $row['qr_code_url'],
            thumbnailUrl: (string) $row['thumbnail_url'],
            sourceCreatedAt: $this->parseDate((string) $row['source_created_at']),
            sourceUpdatedAt: $this->parseDate((string) $row['source_updated_at']),
            tags: $tags,
            imageUrls: $imageUrls,
        );
    }

    private function rollBackQuietly(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }

        try {
            $this->pdo->rollBack();
        } catch (PDOException) {
            // The caller rethrows the original failure, which is more useful than this one.
        }
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone(self::TIME_ZONE))->format(self::DATE_TIME_FORMAT);
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone(self::TIME_ZONE));
    }

    private function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TIME_ZONE))->format(self::DATE_TIME_FORMAT);
    }
}
