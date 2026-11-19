<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use GuzzleHttp\Psr7\HttpFactory;
use MaxServ\App\Controller\IndexController;
use MaxServ\App\Controller\ProductController;
use MaxServ\App\Import\DummyJsonProductClient;
use MaxServ\App\Import\DummyJsonProductMapper;
use MaxServ\App\Import\ProductImporter;
use MaxServ\App\Security\CsrfTokenManager;
use MaxServ\App\Tests\Fake\FakeHttpClient;
use MaxServ\App\Tests\Fake\InMemoryProductRepository;
use MaxServ\Core\Render\TemplateRenderer;
use MaxServ\Core\Tests\Fake\RecordingLogger;
use MaxServ\Core\Tests\Fake\SteppingClock;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Builds controllers with the real templates and in-memory fakes, and reads the pages they render.
 */
final class Pages
{
    private const string TEMPLATES_DIRECTORY = __DIR__ . '/../../../../templates';

    /**
     * Creates the product controller with the real templates.
     *
     * @param InMemoryProductRepository $repository The stored products.
     * @param FakeHttpClient $httpClient Answers the requests to dummyjson.com.
     * @param RecordingLogger $logger Records the logged errors.
     *
     * @return ProductController The controller.
     */
    public static function productController(
        InMemoryProductRepository $repository,
        FakeHttpClient $httpClient = new FakeHttpClient(),
        RecordingLogger $logger = new RecordingLogger(),
    ): ProductController {
        return new ProductController(
            self::templateRenderer(),
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
     * Creates the start page controller with the real templates.
     *
     * @return IndexController The controller.
     */
    public static function indexController(): IndexController
    {
        return new IndexController(self::templateRenderer());
    }

    /**
     * Creates a repository holding the given raw dummyjson.com products.
     *
     * @param array<string, mixed> ...$rawProducts The products, as dummyjson.com returns them.
     *
     * @return InMemoryProductRepository The repository.
     */
    public static function repositoryWith(array ...$rawProducts): InMemoryProductRepository
    {
        $repository = new InMemoryProductRepository();
        $repository->save(array_map(new DummyJsonProductMapper()->map(...), array_values($rawProducts)));

        return $repository;
    }

    /**
     * Creates a request with an empty session.
     *
     * @param string $method The HTTP method.
     * @param string $uri The path.
     * @param array<string, mixed> $parameters The query or form fields.
     *
     * @return Request The request.
     */
    public static function request(string $method, string $uri, array $parameters = []): Request
    {
        $request = Request::create($uri, $method, $parameters);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * Creates an import form submission with a valid token.
     *
     * @param array<string, mixed> $fields The form fields besides the token.
     *
     * @return Request The request.
     */
    public static function importRequest(array $fields): Request
    {
        $request = self::request('POST', '/products/import', $fields);
        $request->request->set('_token', new CsrfTokenManager()->tokenFor($request->getSession()));

        return $request;
    }

    /**
     * Parses a page with the HTML5 parser; markup errors surface as warnings, which fail the test.
     *
     * @param Response $response The page.
     *
     * @return HTMLDocument The parsed page.
     */
    public static function parse(Response $response): HTMLDocument
    {
        return HTMLDocument::createFromString((string) $response->getContent());
    }

    /**
     * Returns the text of a node as a reader sees it: whitespace collapsed and non-breaking spaces as spaces.
     *
     * @param Node|null $node The node.
     *
     * @return string The text, or an empty string for no node.
     */
    public static function text(?Node $node): string
    {
        $text = str_replace("\u{00A0}", ' ', $node?->textContent ?? '');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Returns the one element that matches a selector, failing the test when there is not exactly one.
     *
     * @param HTMLDocument|Element $scope Where to look.
     * @param string $selector A CSS selector.
     *
     * @return Element The element.
     */
    public static function one(HTMLDocument|Element $scope, string $selector): Element
    {
        $matches = $scope->querySelectorAll($selector);
        Assert::assertCount(1, $matches, "Expected exactly one match for $selector.");
        $element = $matches->item(0);
        Assert::assertInstanceOf(Element::class, $element);

        return $element;
    }

    /**
     * Returns the value of an attribute for every element that matches a selector, in document order.
     *
     * @param HTMLDocument|Element $scope Where to look.
     * @param string $selector A CSS selector.
     * @param string $attribute The attribute name.
     *
     * @return list<string> The values.
     */
    public static function attributes(HTMLDocument|Element $scope, string $selector, string $attribute): array
    {
        $values = [];
        foreach ($scope->querySelectorAll($selector) as $element) {
            $values[] = (string) $element->getAttribute($attribute);
        }

        return $values;
    }

    /**
     * Returns the flash messages of a type without removing them.
     *
     * @param Request $request The request whose session holds the messages.
     * @param string $type The message type, such as 'success'.
     *
     * @return list<string> The messages.
     */
    public static function flashes(Request $request, string $type): array
    {
        $session = $request->getSession();
        Assert::assertInstanceOf(Session::class, $session);

        return $session->getFlashBag()->peek($type);
    }

    private static function templateRenderer(): TemplateRenderer
    {
        return new TemplateRenderer(
            new Environment(new FilesystemLoader(self::TEMPLATES_DIRECTORY), ['strict_variables' => true]),
        );
    }
}
