<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Controller;

use MaxServ\App\Controller\ProductController;
use MaxServ\App\Import\DummyJsonPage;
use MaxServ\App\Import\DummyJsonProductClient;
use MaxServ\App\Import\DummyJsonProductMapper;
use MaxServ\App\Import\ImportFailed;
use MaxServ\App\Import\ImportRequest;
use MaxServ\App\Import\ImportResult;
use MaxServ\App\Import\InvalidImportRequest;
use MaxServ\App\Import\ProductImporter;
use MaxServ\App\Pagination\PageRequest;
use MaxServ\App\Product\Dimensions;
use MaxServ\App\Product\Product;
use MaxServ\App\Product\ProductListRequest;
use MaxServ\App\Product\ProductPage;
use MaxServ\App\Security\CsrfTokenManager;
use MaxServ\App\Tests\Fake\DummyJson;
use MaxServ\App\Tests\Fake\FakeClientException;
use MaxServ\App\Tests\Fake\FakeHttpClient;
use MaxServ\App\Tests\Fake\InMemoryProductRepository;
use MaxServ\App\Tests\Support\Pages;
use MaxServ\Core\Render\TemplateRenderer;
use MaxServ\Core\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductController::class)]
#[UsesClass(TemplateRenderer::class)]
#[UsesClass(ProductImporter::class)]
#[UsesClass(DummyJsonProductClient::class)]
#[UsesClass(DummyJsonProductMapper::class)]
#[UsesClass(DummyJsonPage::class)]
#[UsesClass(ImportRequest::class)]
#[UsesClass(ImportResult::class)]
#[UsesClass(ImportFailed::class)]
#[UsesClass(InvalidImportRequest::class)]
#[UsesClass(CsrfTokenManager::class)]
#[UsesClass(PageRequest::class)]
#[UsesClass(ProductListRequest::class)]
#[UsesClass(ProductPage::class)]
#[UsesClass(Product::class)]
#[UsesClass(Dimensions::class)]
final class ProductControllerTest extends TestCase
{
    #[Test]
    public function empty_list_shows_no_products_yet_with_a_link_to_the_import(): void
    {
        $response = Pages::productController(new InMemoryProductRepository())->list([], Pages::request('GET', '/products'));
        $page = Pages::parse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('No products yet', Pages::text(Pages::one($page, 'main h2')));
        self::assertSame('/products/import', Pages::one($page, 'main a[href="/products/import"]')->getAttribute('href'));
        self::assertNull($page->querySelector('table'));
        self::assertNull($page->querySelector('[data-filter-form]'));
    }

    #[Test]
    public function list_summarises_the_products_on_the_page(): void
    {
        $repository = Pages::repositoryWith(...DummyJson::products(1, 25));

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['page' => '2', 'perPage' => '10'])));

        self::assertSame('Showing 11–20 of 25 products', Pages::text(Pages::one($page, '[data-summary]')));
    }

    #[Test]
    public function list_shows_prices_in_euros_with_the_discount_price(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(1, ['price' => 1234.56, 'discountPercentage' => 10.0]));

        $row = Pages::one(Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products'))), '[data-product-row="1"]');

        self::assertSame('Price € 1.111,10', Pages::text(Pages::one($row, '[data-price]')));
        self::assertSame('Original price € 1.234,56', Pages::text(Pages::one($row, 's[data-original-price]')));
        self::assertSame('Discount −10,00 %', Pages::text(Pages::one($row, '[data-discount]')));
    }

    #[Test]
    public function list_shows_only_the_normal_price_without_a_discount(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(1, ['price' => 9.99, 'discountPercentage' => 0]));

        $row = Pages::one(Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products'))), '[data-product-row="1"]');

        self::assertSame('Price € 9,99', Pages::text(Pages::one($row, '[data-price]')));
        self::assertNull($row->querySelector('[data-original-price]'));
        self::assertNull($row->querySelector('[data-discount]'));
    }

    #[Test]
    public function list_shows_a_dash_for_a_product_without_a_brand(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(1, ['brand' => null]));

        $brand = Pages::one(Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products'))), '[data-product-row="1"] [data-brand]');

        self::assertSame('–', Pages::text(Pages::one($brand, '[aria-hidden="true"]')));
        self::assertSame('No brand', Pages::text(Pages::one($brand, 'span:not([aria-hidden])')));
    }

    #[Test]
    public function list_escapes_product_text(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(1, ['title' => '<script>alert(1)</script>']));

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products')));

        self::assertNull($page->querySelector('script'));
        self::assertSame('<script>alert(1)</script>', Pages::text(Pages::one($page, '[data-product-row="1"] a[href="/products/1"]')));
    }

    #[Test]
    public function table_and_cards_show_the_same_products_in_the_same_order(): void
    {
        $repository = Pages::repositoryWith(...DummyJson::products(1, 3));

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['sort' => 'title', 'direction' => 'desc'])));

        self::assertSame(['3', '2', '1'], Pages::attributes($page, '[data-product-row]', 'data-product-row'));
        self::assertSame(['3', '2', '1'], Pages::attributes($page, '[data-product-card]', 'data-product-card'));
    }

    #[Test]
    public function filter_dropdowns_list_every_stored_category_and_brand_with_the_current_one_selected(): void
    {
        $repository = Pages::repositoryWith(
            DummyJson::product(1, ['category' => 'smartphones', 'brand' => 'Apple']),
            DummyJson::product(2, ['category' => 'beauty', 'brand' => 'Essence']),
            DummyJson::product(3, ['category' => 'beauty', 'brand' => null]),
        );

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['category' => 'beauty'])));

        self::assertSame('Category', Pages::text(Pages::one($page, 'label[for="filter-category"]')));
        self::assertSame(['', 'beauty', 'smartphones'], Pages::attributes($page, '#filter-category option', 'value'));
        self::assertSame(['beauty'], Pages::attributes($page, '#filter-category option[selected]', 'value'));
        self::assertSame(['', 'Apple', 'Essence'], Pages::attributes($page, '#filter-brand option', 'value'));
    }

    #[Test]
    public function category_and_brand_filters_show_only_matching_products(): void
    {
        $repository = Pages::repositoryWith(
            DummyJson::product(1, ['category' => 'smartphones', 'brand' => 'Apple']),
            DummyJson::product(2, ['category' => 'smartphones', 'brand' => 'Samsung']),
            DummyJson::product(3, ['category' => 'laptops', 'brand' => 'Apple']),
        );
        $request = Pages::request('GET', '/products', ['category' => 'smartphones', 'brand' => 'Apple']);

        $page = Pages::parse(Pages::productController($repository)->list([], $request));

        self::assertSame(['1'], Pages::attributes($page, '[data-product-row]', 'data-product-row'));
        self::assertSame('Showing 1–1 of 1 products', Pages::text(Pages::one($page, '[data-summary]')));
        self::assertSame('Clear filters', Pages::text(Pages::one($page, '[data-filter-form] a')));
    }

    #[Test]
    public function filter_without_matches_says_so(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(1));

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['category' => 'unknown'])));

        self::assertSame('No products match the filters', Pages::text(Pages::one($page, 'main h2:not(#filters-title)')));
        self::assertNull($page->querySelector('table'));
    }

    #[Test]
    public function filter_matches_case_exactly(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(1, ['brand' => 'Apple']));

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['brand' => 'apple'])));

        self::assertSame([], Pages::attributes($page, '[data-product-row]', 'data-product-row'));
        self::assertSame('No products match the filters', Pages::text(Pages::one($page, 'main h2:not(#filters-title)')));
    }

    #[Test]
    public function brand_with_special_characters_is_escaped_in_options_and_links(): void
    {
        $repository = Pages::repositoryWith(...array_map(
            static fn (int $id): array => DummyJson::product($id, ['brand' => 'Dolce & Gabbana "DG"']),
            range(1, 11),
        ));
        $request = Pages::request('GET', '/products', ['brand' => 'Dolce & Gabbana "DG"', 'perPage' => '10']);

        $page = Pages::parse(Pages::productController($repository)->list([], $request));

        self::assertSame(['Dolce & Gabbana "DG"'], Pages::attributes($page, '#filter-brand option[selected]', 'value'));
        self::assertSame(
            '/products?brand=Dolce%20%26%20Gabbana%20%22DG%22&page=2&perPage=10',
            Pages::one($page, '[data-pagination] a[rel="next"]')->getAttribute('href'),
        );
    }

    #[Test]
    public function clearing_the_only_filter_links_to_the_plain_list(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(1, ['category' => 'beauty']));

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['category' => 'beauty'])));

        self::assertSame('/products', Pages::one($page, '[data-filter-form] a')->getAttribute('href'));
    }

    #[Test]
    public function ascending_brand_sort_keeps_products_without_a_brand_last(): void
    {
        $repository = Pages::repositoryWith(
            DummyJson::product(1, ['brand' => null]),
            DummyJson::product(2, ['brand' => 'Samsung']),
            DummyJson::product(3, ['brand' => 'Apple']),
        );

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['sort' => 'brand'])));

        self::assertSame(['3', '2', '1'], Pages::attributes($page, '[data-product-row]', 'data-product-row'));
    }

    #[Test]
    public function descending_brand_sort_keeps_products_without_a_brand_last(): void
    {
        $repository = Pages::repositoryWith(
            DummyJson::product(1, ['brand' => null]),
            DummyJson::product(2, ['brand' => 'Apple']),
            DummyJson::product(3, ['brand' => 'Samsung']),
        );
        $request = Pages::request('GET', '/products', ['sort' => 'brand', 'direction' => 'desc']);

        $page = Pages::parse(Pages::productController($repository)->list([], $request));

        self::assertSame(['3', '2', '1'], Pages::attributes($page, '[data-product-row]', 'data-product-row'));
    }

    #[Test]
    public function price_sort_uses_the_discount_price(): void
    {
        $repository = Pages::repositoryWith(
            DummyJson::product(1, ['price' => 10.00, 'discountPercentage' => 50.0]),
            DummyJson::product(2, ['price' => 8.00, 'discountPercentage' => 0]),
            DummyJson::product(3, ['price' => 6.00, 'discountPercentage' => 0]),
        );

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['sort' => 'price'])));

        self::assertSame(['1', '3', '2'], Pages::attributes($page, '[data-product-row]', 'data-product-row'));
    }

    #[Test]
    public function sorted_header_states_its_order_and_links_to_the_reverse_order(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(1));

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['sort' => 'title'])));

        $sortedHeader = Pages::one($page, 'th[aria-sort]');
        self::assertSame('ascending', $sortedHeader->getAttribute('aria-sort'));
        $titleLink = Pages::one($sortedHeader, 'a[data-sort="title"]');
        self::assertSame('/products?sort=title&direction=desc', $titleLink->getAttribute('href'));
        self::assertSame('Title, sort descending', Pages::text($titleLink));
        $priceLink = Pages::one($page, 'a[data-sort="price"]');
        self::assertSame('/products?sort=price', $priceLink->getAttribute('href'));
        self::assertSame('Price, sort ascending', Pages::text($priceLink));
    }

    #[Test]
    public function caption_describes_the_order_and_page(): void
    {
        $repository = Pages::repositoryWith(...DummyJson::products(1, 25));
        $request = Pages::request('GET', '/products', ['sort' => 'price', 'direction' => 'desc', 'perPage' => '10', 'page' => '2']);

        $page = Pages::parse(Pages::productController($repository)->list([], $request));

        self::assertSame('Products, sorted by price, high to low, page 2 of 3', Pages::text(Pages::one($page, 'table caption')));
    }

    #[Test]
    public function sort_menu_lists_every_order_and_marks_the_current_one(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(1, ['category' => 'beauty']));
        $request = Pages::request('GET', '/products', ['category' => 'beauty', 'sort' => 'price', 'direction' => 'desc']);

        $menu = Pages::one(Pages::parse(Pages::productController($repository)->list([], $request)), '[data-sort-menu]');

        self::assertSame('Sort: Price, high to low', Pages::text(Pages::one($menu, 'summary')));
        self::assertCount(9, $menu->querySelectorAll('a'));
        $current = Pages::one($menu, 'a[aria-current="true"]');
        self::assertSame('Price, high to low', Pages::text($current));
        self::assertSame('/products?category=beauty&sort=title', Pages::one($menu, 'li:nth-child(2) a')->getAttribute('href'));
        self::assertSame('/products?category=beauty', Pages::one($menu, 'li:first-child a')->getAttribute('href'));
    }

    #[Test]
    public function pager_links_keep_the_filters_and_sorting(): void
    {
        $repository = Pages::repositoryWith(...DummyJson::products(1, 25));
        $request = Pages::request('GET', '/products', ['category' => 'beauty', 'sort' => 'title', 'perPage' => '10']);

        $page = Pages::parse(Pages::productController($repository)->list([], $request));

        self::assertSame('/products?category=beauty&sort=title&page=2&perPage=10', Pages::one($page, '[data-pagination] a[rel="next"]')->getAttribute('href'));
        self::assertSame(['title'], Pages::attributes($page, '[data-filter-form] input[type="hidden"][name="sort"]', 'value'));
    }

    #[Test]
    public function list_links_to_the_other_pages_and_keeps_the_page_size(): void
    {
        $repository = Pages::repositoryWith(...DummyJson::products(1, 25));

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['page' => '2', 'perPage' => '10'])));

        self::assertSame('/products?perPage=10', Pages::one($page, '[data-pagination] a[rel="prev"]')->getAttribute('href'));
        self::assertSame('Previous page', Pages::text(Pages::one($page, '[data-pagination] a[rel="prev"]')));
        self::assertSame('/products?page=3&perPage=10', Pages::one($page, '[data-pagination] a[rel="next"]')->getAttribute('href'));
        self::assertSame(array_map(strval(...), range(11, 20)), Pages::attributes($page, '[data-product-row]', 'data-product-row'));
    }

    #[Test]
    public function page_links_mark_the_current_page_and_show_gaps(): void
    {
        $repository = Pages::repositoryWith(...DummyJson::products(1, 100));

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['page' => '5', 'perPage' => '10'])));

        $pagination = Pages::one($page, '[data-pagination]');
        self::assertSame('Page 5', Pages::text(Pages::one($pagination, 'a[aria-current="page"]')));
        $labels = array_map(Pages::text(...), iterator_to_array($pagination->querySelectorAll('li[data-page-link]')));
        self::assertSame(['Page 1', '…', 'Page 4', 'Page 5', 'Page 6', '…', 'Page 10'], $labels);
        self::assertSame('Pagination', $pagination->getAttribute('aria-label'));
    }

    #[Test]
    public function per_page_links_mark_the_current_size(): void
    {
        $repository = Pages::repositoryWith(...DummyJson::products(1, 25));

        $page = Pages::parse(Pages::productController($repository)->list([], Pages::request('GET', '/products', ['perPage' => '50'])));

        $perPage = Pages::one($page, '[data-per-page]');
        self::assertSame('50', Pages::text(Pages::one($perPage, 'a[aria-current="true"]')));
        self::assertSame(['/products?perPage=10', '/products', '/products?perPage=50'], Pages::attributes($perPage, 'a', 'href'));
    }

    #[Test]
    public function list_shows_the_import_result_as_a_status_message(): void
    {
        $request = Pages::request('GET', '/products');
        $session = $request->getSession();
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\Session\Session::class, $session);
        $session->getFlashBag()->add('success', 'Imported 3 products and skipped 0 with invalid data.');

        $page = Pages::parse(Pages::productController(Pages::repositoryWith(DummyJson::product(1)))->list([], $request));

        self::assertSame('Imported 3 products and skipped 0 with invalid data.', Pages::text(Pages::one($page, '[role="status"] li')));
    }

    #[Test]
    public function detail_page_shows_the_product(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(5));

        $response = Pages::productController($repository)->show(['id' => '5'], Pages::request('GET', '/products/5'));
        $page = Pages::parse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Example product 5', Pages::text(Pages::one($page, 'h1')));
        self::assertSame('Example product 5', Pages::text(Pages::one($page, 'nav[aria-label="Breadcrumb"] [aria-current="page"]')));
        self::assertSame(['beauty', 'mascara'], array_map(Pages::text(...), iterator_to_array($page->querySelectorAll('[data-tags] li'))));
        self::assertSame(
            ['Example product 5, image 1 of 2', 'Example product 5, image 2 of 2'],
            Pages::attributes($page, '[data-gallery-image]', 'alt'),
        );
        self::assertSame('https://cdn.example.com/products/5/2.webp', $page->querySelectorAll('[data-gallery-image]')->item(1)?->getAttribute('src'));
        self::assertSame('Rated 2,56 out of 5', Pages::text(Pages::one($page, '[data-rating]')));
    }

    #[Test]
    public function page_title_shows_special_characters_once_escaped(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(5, ['title' => "Dior J'adore & Co <Limited>"]));

        $page = Pages::parse(Pages::productController($repository)->show(['id' => '5'], Pages::request('GET', '/products/5')));

        self::assertSame("Dior J'adore & Co <Limited> · Product catalogue", $page->title);
        self::assertSame("Dior J'adore & Co <Limited>", Pages::text(Pages::one($page, 'h1')));
    }

    #[Test]
    public function detail_page_marks_products_as_the_current_section_not_the_current_page(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(5));

        $page = Pages::parse(Pages::productController($repository)->show(['id' => '5'], Pages::request('GET', '/products/5')));

        self::assertSame('true', Pages::one($page, 'nav[aria-label="Main"] a[href="/products"]')->getAttribute('aria-current'));
        self::assertCount(1, $page->querySelectorAll('[aria-current="page"]'));
    }

    #[Test]
    public function list_marks_products_as_the_current_page(): void
    {
        $page = Pages::parse(Pages::productController(Pages::repositoryWith(DummyJson::product(1)))->list([], Pages::request('GET', '/products')));

        self::assertSame('page', Pages::one($page, 'nav[aria-label="Main"] a[href="/products"]')->getAttribute('aria-current'));
    }

    #[Test]
    public function detail_page_without_images_shows_the_thumbnail(): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(5, ['images' => []]));

        $page = Pages::parse(Pages::productController($repository)->show(['id' => '5'], Pages::request('GET', '/products/5')));

        $image = Pages::one($page, '[data-gallery-image]');
        self::assertSame('https://cdn.example.com/products/5/thumbnail.webp', $image->getAttribute('src'));
        self::assertSame('Example product 5', $image->getAttribute('alt'));
    }

    /**
     * The three availability statuses as dummyjson.com spells them.
     *
     * @return iterable<string, array{string}>
     */
    public static function availabilityStatuses(): iterable
    {
        yield 'in stock' => ['In Stock'];
        yield 'low stock' => ['Low Stock'];
        yield 'out of stock' => ['Out of Stock'];
    }

    #[Test]
    #[DataProvider('availabilityStatuses')]
    public function availability_is_spelled_out(string $status): void
    {
        $repository = Pages::repositoryWith(DummyJson::product(5, ['availabilityStatus' => $status]));

        $page = Pages::parse(Pages::productController($repository)->show(['id' => '5'], Pages::request('GET', '/products/5')));

        self::assertSame($status, Pages::text(Pages::one($page, '[data-availability]')));
    }

    #[Test]
    public function unknown_product_gets_the_not_found_page(): void
    {
        $response = Pages::productController(new InMemoryProductRepository())->show(['id' => '999999'], Pages::request('GET', '/products/999999'));
        $page = Pages::parse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Product not found', Pages::text(Pages::one($page, 'h1')));
        self::assertSame('Back to the products', Pages::text(Pages::one($page, 'main a[href="/products"]')));
    }

    #[Test]
    public function import_form_shows_the_default_values_with_labels_and_hints(): void
    {
        $response = Pages::productController(new InMemoryProductRepository())->importForm([], Pages::request('GET', '/products/import'));
        $page = Pages::parse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('200', Pages::one($page, '#count')->getAttribute('value'));
        self::assertSame('0', Pages::one($page, '#offset')->getAttribute('value'));
        self::assertSame('Number of products', Pages::text(Pages::one($page, 'label[for="count"]')));
        self::assertSame('count-hint', Pages::one($page, '#count')->getAttribute('aria-describedby'));
        self::assertSame('A whole number from 1 to 200.', Pages::text(Pages::one($page, '#count-hint')));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) Pages::one($page, 'input[name="_token"]')->getAttribute('value'));
        self::assertSame('page', Pages::one($page, 'nav[aria-label="Main"] a[href="/products/import"]')->getAttribute('aria-current'));
    }

    #[Test]
    public function import_without_a_valid_token_is_forbidden_and_imports_nothing(): void
    {
        $httpClient = new FakeHttpClient();
        $repository = new InMemoryProductRepository();
        $request = Pages::request('POST', '/products/import', ['count' => '10', 'offset' => '0', '_token' => 'forged']);

        $response = Pages::productController($repository, $httpClient)->import([], $request);
        $page = Pages::parse($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('This form has expired', Pages::text(Pages::one($page, 'h1')));
        self::assertSame('Open the import form again', Pages::text(Pages::one($page, 'main a[href="/products/import"]')));
        self::assertSame([], $httpClient->requests());
        self::assertSame([], $repository->storedIds());
    }

    #[Test]
    public function import_with_a_valid_token_stores_products_and_redirects_with_the_result(): void
    {
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, DummyJson::products(1, 3)));
        $repository = new InMemoryProductRepository();
        $request = Pages::importRequest(['count' => '3', 'offset' => '0']);

        $response = Pages::productController($repository, $httpClient)->import([], $request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/products', $response->headers->get('Location'));
        self::assertSame([1, 2, 3], $repository->storedIds());
        self::assertSame(['Imported 3 products and skipped 0 with invalid data.'], Pages::flashes($request, 'success'));
    }

    #[Test]
    public function import_with_invalid_fields_shows_the_form_again_with_every_error(): void
    {
        $httpClient = new FakeHttpClient();
        $request = Pages::importRequest(['count' => '0', 'offset' => '10001']);

        $response = Pages::productController(new InMemoryProductRepository(), $httpClient)->import([], $request);
        $page = Pages::parse($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $httpClient->requests());
        $summary = Pages::one($page, '[role="alert"]');
        self::assertSame('There is a problem', Pages::text(Pages::one($summary, 'h2')));
        self::assertSame(['#count', '#offset'], Pages::attributes($summary, 'a', 'href'));
        self::assertSame('Error: Enter a whole number from 1 to 200.', Pages::text(Pages::one($page, '#count-error')));
        self::assertSame('Error: Enter a whole number from 0 to 10000.', Pages::text(Pages::one($page, '#offset-error')));
        self::assertSame('true', Pages::one($page, '#count')->getAttribute('aria-invalid'));
        self::assertSame('count-hint count-error', Pages::one($page, '#count')->getAttribute('aria-describedby'));
        self::assertSame('0', Pages::one($page, '#count')->getAttribute('value'));
        self::assertSame('10001', Pages::one($page, '#offset')->getAttribute('value'));
    }

    #[Test]
    public function import_keeps_short_entered_values_escaped_and_drops_long_ones(): void
    {
        $request = Pages::importRequest(['count' => '<script>x</script>', 'offset' => str_repeat('9', 33)]);

        $page = Pages::parse(Pages::productController(new InMemoryProductRepository())->import([], $request));

        self::assertNull($page->querySelector('script'));
        self::assertSame('<script>x</script>', Pages::one($page, '#count')->getAttribute('value'));
        self::assertSame('', Pages::one($page, '#offset')->getAttribute('value'));
    }

    #[Test]
    public function import_past_the_last_product_says_so(): void
    {
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, []));
        $request = Pages::importRequest(['count' => '10', 'offset' => '10000']);

        (void) Pages::productController(new InMemoryProductRepository(), $httpClient)->import([], $request);

        self::assertSame(
            ['No products imported: the offset is past the last of the 194 dummyjson.com products.'],
            Pages::flashes($request, 'success'),
        );
    }

    #[Test]
    public function failed_import_shows_the_form_again_with_a_generic_message_and_logs_the_cause(): void
    {
        $httpClient = new FakeHttpClient(new FakeClientException('Could not resolve host.'));
        $logger = new RecordingLogger();
        $request = Pages::importRequest(['count' => '10', 'offset' => '5']);

        $response = Pages::productController(new InMemoryProductRepository(), $httpClient, $logger)->import([], $request);
        $page = Pages::parse($response);

        self::assertSame(503, $response->getStatusCode());
        $alert = Pages::text(Pages::one($page, '[role="alert"]'));
        self::assertStringStartsWith('The import failed', $alert);
        self::assertStringNotContainsString('Could not resolve host', $alert);
        self::assertSame('10', Pages::one($page, '#count')->getAttribute('value'));
        self::assertSame('5', Pages::one($page, '#offset')->getAttribute('value'));
        self::assertSame(
            ['Product import failed: The dummyjson.com request failed. Could not resolve host.'],
            $logger->errors(),
        );
    }

    #[Test]
    public function skipped_products_are_counted_in_the_message_and_logged(): void
    {
        $products = DummyJson::products(1, 3);
        $products[2]['availabilityStatus'] = 'Discontinued';
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, $products));
        $logger = new RecordingLogger();
        $request = Pages::importRequest(['count' => '3', 'offset' => '0']);

        (void) Pages::productController(new InMemoryProductRepository(), $httpClient, $logger)->import([], $request);

        self::assertSame(['Imported 2 products and skipped 1 with invalid data.'], Pages::flashes($request, 'success'));
        self::assertSame(
            ['Product import skipped a product: Product 3: availabilityStatus is not a known availability status.'],
            $logger->errors(),
        );
    }
}
