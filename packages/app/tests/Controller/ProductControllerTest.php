<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Controller;

use GuzzleHttp\Psr7\HttpFactory;
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
use MaxServ\App\Product\ProductPage;
use MaxServ\App\Security\CsrfTokenManager;
use MaxServ\App\Tests\Fake\DummyJson;
use MaxServ\App\Tests\Fake\FakeClientException;
use MaxServ\App\Tests\Fake\FakeHttpClient;
use MaxServ\App\Tests\Fake\InMemoryProductRepository;
use MaxServ\Core\Render\TemplateRenderer;
use MaxServ\Core\Tests\Fake\RecordingLogger;
use MaxServ\Core\Tests\Fake\SteppingClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
#[UsesClass(ProductPage::class)]
#[UsesClass(Product::class)]
#[UsesClass(Dimensions::class)]
final class ProductControllerTest extends TestCase
{
    private const string TEMPLATES_DIRECTORY = __DIR__ . '/../../../../templates';

    #[Test]
    public function empty_list_shows_the_import_form_and_no_table(): void
    {
        $response = self::controller(new InMemoryProductRepository())->list([], self::request('GET', '/products'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('There are no products yet.', $response->getContent());
        self::assertStringContainsString('action="/products/import"', $response->getContent());
        self::assertStringNotContainsString('<table>', $response->getContent());
    }

    #[Test]
    public function list_shows_prices_in_euros_with_the_discount_price(): void
    {
        $repository = self::repositoryWith(DummyJson::product(1, ['price' => 1234.56, 'discountPercentage' => 10.0]));

        $content = self::controller($repository)->list([], self::request('GET', '/products'))->getContent();

        self::assertStringContainsString('<s class="original-price">€&nbsp;1.234,56</s>', $content);
        self::assertStringContainsString('<span class="discount-price">€&nbsp;1.111,10</span>', $content);
    }

    #[Test]
    public function list_shows_only_the_normal_price_without_a_discount(): void
    {
        $repository = self::repositoryWith(DummyJson::product(1, ['price' => 9.99, 'discountPercentage' => 0]));

        $content = self::controller($repository)->list([], self::request('GET', '/products'))->getContent();

        self::assertStringContainsString('€&nbsp;9,99', $content);
        self::assertStringNotContainsString('<s class="original-price">', $content);
    }

    #[Test]
    public function list_shows_a_dash_for_a_product_without_a_brand(): void
    {
        $repository = self::repositoryWith(DummyJson::product(1, ['brand' => null]));

        $content = self::controller($repository)->list([], self::request('GET', '/products'))->getContent();

        self::assertStringContainsString('<td>–</td>', $content);
    }

    #[Test]
    public function list_escapes_product_text(): void
    {
        $repository = self::repositoryWith(DummyJson::product(1, ['title' => '<script>alert(1)</script>']));

        $content = self::controller($repository)->list([], self::request('GET', '/products'))->getContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $content);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);
    }

    #[Test]
    public function filter_dropdowns_list_every_stored_category_and_brand_with_the_current_one_selected(): void
    {
        $repository = self::repositoryWith(
            DummyJson::product(1, ['category' => 'smartphones', 'brand' => 'Apple']),
            DummyJson::product(2, ['category' => 'beauty', 'brand' => 'Essence']),
            DummyJson::product(3, ['category' => 'beauty', 'brand' => null]),
        );

        $content = self::controller($repository)->list([], self::request('GET', '/products', ['category' => 'beauty']))->getContent();

        self::assertSame(1, substr_count($content, '<option value="beauty" selected>beauty</option>'));
        self::assertStringContainsString('<option value="smartphones">smartphones</option>', $content);
        self::assertStringContainsString('<option value="Apple">Apple</option>', $content);
        self::assertStringContainsString('<option value="Essence">Essence</option>', $content);
    }

    #[Test]
    public function category_and_brand_filters_show_only_matching_products(): void
    {
        $repository = self::repositoryWith(
            DummyJson::product(1, ['category' => 'smartphones', 'brand' => 'Apple']),
            DummyJson::product(2, ['category' => 'smartphones', 'brand' => 'Samsung']),
            DummyJson::product(3, ['category' => 'laptops', 'brand' => 'Apple']),
        );
        $request = self::request('GET', '/products', ['category' => 'smartphones', 'brand' => 'Apple']);

        $content = self::controller($repository)->list([], $request)->getContent();

        self::assertStringContainsString('href="/products/1"', $content);
        self::assertStringNotContainsString('href="/products/2"', $content);
        self::assertStringNotContainsString('href="/products/3"', $content);
        self::assertStringContainsString('(1 products)', $content);
        self::assertStringContainsString('Clear filters', $content);
    }

    #[Test]
    public function filter_without_matches_says_so(): void
    {
        $repository = self::repositoryWith(DummyJson::product(1));

        $content = self::controller($repository)->list([], self::request('GET', '/products', ['category' => 'unknown']))->getContent();

        self::assertStringContainsString('No products match the filters.', $content);
        self::assertStringNotContainsString('<table>', $content);
    }

    #[Test]
    public function filter_matches_case_exactly(): void
    {
        $repository = self::repositoryWith(DummyJson::product(1, ['brand' => 'Apple']));

        $content = self::controller($repository)->list([], self::request('GET', '/products', ['brand' => 'apple']))->getContent();

        self::assertStringContainsString('No products match the filters.', $content);
    }

    #[Test]
    public function brand_with_special_characters_is_escaped_in_options_and_links(): void
    {
        $repository = self::repositoryWith(...array_map(
            static fn (int $id): array => DummyJson::product($id, ['brand' => 'Dolce & Gabbana "DG"']),
            range(1, 11),
        ));
        $request = self::request('GET', '/products', ['brand' => 'Dolce & Gabbana "DG"', 'perPage' => '10']);

        $content = self::controller($repository)->list([], $request)->getContent();

        self::assertStringContainsString('<option value="Dolce &amp; Gabbana &quot;DG&quot;" selected>Dolce &amp; Gabbana &quot;DG&quot;</option>', $content);
        self::assertStringContainsString('href="/products?brand=Dolce%20%26%20Gabbana%20%22DG%22&amp;page=2&amp;perPage=10" rel="next"', $content);
    }

    #[Test]
    public function clearing_the_only_filter_links_to_the_plain_list(): void
    {
        $repository = self::repositoryWith(DummyJson::product(1, ['category' => 'beauty']));

        $content = self::controller($repository)->list([], self::request('GET', '/products', ['category' => 'beauty']))->getContent();

        self::assertStringContainsString('<a href="/products">Clear filters</a>', $content);
    }

    #[Test]
    public function ascending_brand_sort_keeps_products_without_a_brand_last(): void
    {
        $repository = self::repositoryWith(
            DummyJson::product(1, ['brand' => null]),
            DummyJson::product(2, ['brand' => 'Samsung']),
            DummyJson::product(3, ['brand' => 'Apple']),
        );

        $content = self::controller($repository)->list([], self::request('GET', '/products', ['sort' => 'brand']))->getContent();

        self::assertSame([3, 2, 1], self::productIdsInOrder($content));
    }

    #[Test]
    public function price_sort_uses_the_discount_price(): void
    {
        $repository = self::repositoryWith(
            DummyJson::product(1, ['price' => 10.00, 'discountPercentage' => 50.0]),
            DummyJson::product(2, ['price' => 8.00, 'discountPercentage' => 0]),
            DummyJson::product(3, ['price' => 6.00, 'discountPercentage' => 0]),
        );

        $content = self::controller($repository)->list([], self::request('GET', '/products', ['sort' => 'price']))->getContent();

        self::assertSame([1, 3, 2], self::productIdsInOrder($content));
    }

    #[Test]
    public function descending_brand_sort_keeps_products_without_a_brand_last(): void
    {
        $repository = self::repositoryWith(
            DummyJson::product(1, ['brand' => null]),
            DummyJson::product(2, ['brand' => 'Apple']),
            DummyJson::product(3, ['brand' => 'Samsung']),
        );
        $request = self::request('GET', '/products', ['sort' => 'brand', 'direction' => 'desc']);

        $content = self::controller($repository)->list([], $request)->getContent();

        self::assertSame([3, 2, 1], self::productIdsInOrder($content));
    }

    #[Test]
    public function sorted_header_shows_its_direction_and_links_to_the_reverse_order(): void
    {
        $repository = self::repositoryWith(DummyJson::product(1));

        $content = self::controller($repository)->list([], self::request('GET', '/products', ['sort' => 'title']))->getContent();

        self::assertStringContainsString('aria-sort="ascending"', $content);
        self::assertStringContainsString('href="/products?sort=title&amp;direction=desc">Title</a> <span aria-hidden="true">▲</span>', $content);
        self::assertStringContainsString('href="/products?sort=price">Price</a>', $content);
    }

    #[Test]
    public function pager_links_keep_the_filters_and_sorting(): void
    {
        $repository = self::repositoryWith(...DummyJson::products(1, 25));
        $request = self::request('GET', '/products', ['category' => 'beauty', 'sort' => 'title', 'perPage' => '10']);

        $content = self::controller($repository)->list([], $request)->getContent();

        self::assertStringContainsString('href="/products?category=beauty&amp;sort=title&amp;page=2&amp;perPage=10" rel="next"', $content);
        self::assertStringContainsString('<input type="hidden" name="sort" value="title">', $content);
    }

    #[Test]
    public function list_links_to_the_other_pages_and_keeps_the_page_size(): void
    {
        $repository = self::repositoryWith(...DummyJson::products(1, 25));

        $content = self::controller($repository)->list([], self::request('GET', '/products', ['page' => '2', 'perPage' => '10']))->getContent();

        self::assertStringContainsString('Page 2 of 3 (25 products)', $content);
        self::assertStringContainsString('href="/products?perPage=10" rel="prev"', $content);
        self::assertStringContainsString('href="/products?page=3&amp;perPage=10"', $content);
        self::assertStringContainsString('href="/products/11"', $content);
        self::assertStringNotContainsString('href="/products/10"', $content);
    }

    #[Test]
    public function detail_page_shows_the_product(): void
    {
        $repository = self::repositoryWith(DummyJson::product(5));

        $response = self::controller($repository)->show(['id' => '5'], self::request('GET', '/products/5'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Example product 5</h1>', $response->getContent());
        self::assertStringContainsString('beauty, mascara', $response->getContent());
        self::assertStringContainsString('src="https://cdn.example.com/products/5/2.webp"', $response->getContent());
    }

    #[Test]
    public function unknown_product_gets_the_not_found_page(): void
    {
        $response = self::controller(new InMemoryProductRepository())->show(['id' => '999999'], self::request('GET', '/products/999999'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Product not found', $response->getContent());
    }

    #[Test]
    public function import_without_a_valid_token_is_forbidden_and_imports_nothing(): void
    {
        $httpClient = new FakeHttpClient();
        $repository = new InMemoryProductRepository();
        $request = self::request('POST', '/products/import', ['count' => '10', 'offset' => '0', '_token' => 'forged']);

        $response = self::controller($repository, $httpClient)->import([], $request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $httpClient->requests());
        self::assertSame([], $repository->storedIds());
    }

    #[Test]
    public function import_with_a_valid_token_stores_products_and_redirects_with_the_result(): void
    {
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, DummyJson::products(1, 3)));
        $repository = new InMemoryProductRepository();
        $request = self::importRequest(['count' => '3', 'offset' => '0']);

        $response = self::controller($repository, $httpClient)->import([], $request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/products', $response->headers->get('Location'));
        self::assertSame([1, 2, 3], $repository->storedIds());
        self::assertSame(['Imported 3 products and skipped 0 with invalid data.'], self::flashes($request, 'success'));
    }

    #[Test]
    public function import_with_a_count_above_the_limit_shows_the_allowed_ranges(): void
    {
        $httpClient = new FakeHttpClient();
        $request = self::importRequest(['count' => '201', 'offset' => '0']);

        $response = self::controller(new InMemoryProductRepository(), $httpClient)->import([], $request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame([], $httpClient->requests());
        self::assertSame([ImportRequest::allowedValuesMessage()], self::flashes($request, 'error'));
    }

    #[Test]
    public function import_past_the_last_product_says_so(): void
    {
        $httpClient = new FakeHttpClient(DummyJson::pageResponse(194, []));
        $request = self::importRequest(['count' => '10', 'offset' => '10000']);

        (void) self::controller(new InMemoryProductRepository(), $httpClient)->import([], $request);

        self::assertSame(
            ['No products imported: the offset is past the last of the 194 dummyjson.com products.'],
            self::flashes($request, 'success'),
        );
    }

    #[Test]
    public function failed_import_shows_a_generic_message_and_logs_the_cause(): void
    {
        $httpClient = new FakeHttpClient(new FakeClientException('Could not resolve host.'));
        $logger = new RecordingLogger();
        $request = self::importRequest(['count' => '10', 'offset' => '0']);

        (void) self::controller(new InMemoryProductRepository(), $httpClient, $logger)->import([], $request);

        $messages = self::flashes($request, 'error');
        self::assertCount(1, $messages);
        self::assertStringStartsWith('The import failed', $messages[0]);
        self::assertStringNotContainsString('Could not resolve host', $messages[0]);
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
        $request = self::importRequest(['count' => '3', 'offset' => '0']);

        (void) self::controller(new InMemoryProductRepository(), $httpClient, $logger)->import([], $request);

        self::assertSame(['Imported 2 products and skipped 1 with invalid data.'], self::flashes($request, 'success'));
        self::assertSame(
            ['Product import skipped a product: Product 3: availabilityStatus is not a known availability status.'],
            $logger->errors(),
        );
    }

    private static function controller(
        InMemoryProductRepository $repository,
        FakeHttpClient $httpClient = new FakeHttpClient(),
        RecordingLogger $logger = new RecordingLogger(),
    ): ProductController {
        $twig = new Environment(new FilesystemLoader(self::TEMPLATES_DIRECTORY), ['strict_variables' => true]);

        return new ProductController(
            new TemplateRenderer($twig),
            $repository,
            new ProductImporter(
                new DummyJsonProductClient($httpClient, new HttpFactory(), new SteppingClock()),
                new DummyJsonProductMapper(),
                $repository,
            ),
            new CsrfTokenManager(),
            $logger,
        );
    }

    /**
     * @param array<string, mixed> ...$rawProducts
     */
    private static function repositoryWith(array ...$rawProducts): InMemoryProductRepository
    {
        $repository = new InMemoryProductRepository();
        $repository->save(array_map(new DummyJsonProductMapper()->map(...), array_values($rawProducts)));

        return $repository;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function request(string $method, string $uri, array $parameters = []): Request
    {
        $request = Request::create($uri, $method, $parameters);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function importRequest(array $fields): Request
    {
        $request = self::request('POST', '/products/import', $fields);
        $token = new CsrfTokenManager()->tokenFor($request->getSession());
        $request->request->set('_token', $token);

        return $request;
    }

    /**
     * @return list<int> The ids of the products linked from the table rows, in page order.
     */
    private static function productIdsInOrder(string $content): array
    {
        preg_match_all('~<td><a href="/products/(\\d+)">~', $content, $matches);

        return array_map(intval(...), $matches[1]);
    }

    /**
     * @return list<string>
     */
    private static function flashes(Request $request, string $type): array
    {
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session->getFlashBag()->peek($type);
    }
}
