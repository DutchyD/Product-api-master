<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Controller;

use Closure;
use MaxServ\App\Tests\Fake\DummyJson;
use MaxServ\App\Tests\Fake\FakeClientException;
use MaxServ\App\Tests\Fake\FakeHttpClient;
use MaxServ\App\Tests\Fake\InMemoryProductRepository;
use MaxServ\App\Tests\Support\Pages;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders every page and state with the real templates and checks the accessibility rules that markup can show.
 */
#[CoversNothing]
final class PagesTest extends TestCase
{
    /**
     * Every page and state of the application, each rendered by a closure.
     *
     * @return iterable<string, array{Closure(): Response}>
     */
    public static function pages(): iterable
    {
        $filled = static fn (): InMemoryProductRepository => Pages::repositoryWith(
            ...[...DummyJson::products(1, 24), DummyJson::product(25, ['brand' => null, 'discountPercentage' => 0])],
        );

        yield 'start page' => [static fn (): Response => Pages::indexController()->index()];
        yield 'empty list' => [static fn (): Response => Pages::productController(new InMemoryProductRepository())->list([], Pages::request('GET', '/products'))];
        yield 'filled list' => [static fn (): Response => Pages::productController($filled())->list([], Pages::request('GET', '/products', ['perPage' => '10', 'page' => '2']))];
        yield 'list without matches' => [static fn (): Response => Pages::productController($filled())->list([], Pages::request('GET', '/products', ['category' => 'unknown']))];
        yield 'list sorted descending' => [static fn (): Response => Pages::productController($filled())->list([], Pages::request('GET', '/products', ['sort' => 'price', 'direction' => 'desc']))];
        yield 'detail with images' => [static fn (): Response => Pages::productController($filled())->show(['id' => '3'], Pages::request('GET', '/products/3'))];
        yield 'detail without images' => [static fn (): Response => Pages::productController(Pages::repositoryWith(DummyJson::product(7, ['images' => [], 'tags' => []])))->show(['id' => '7'], Pages::request('GET', '/products/7'))];
        yield 'import form' => [static fn (): Response => Pages::productController(new InMemoryProductRepository())->importForm([], Pages::request('GET', '/products/import'))];
        yield 'import with errors' => [static fn (): Response => Pages::productController(new InMemoryProductRepository())->import([], Pages::importRequest(['count' => '0', 'offset' => 'x']))];
        yield 'import failure' => [static fn (): Response => Pages::productController(new InMemoryProductRepository(), new FakeHttpClient(new FakeClientException('down')))->import([], Pages::importRequest(['count' => '5', 'offset' => '0']))];
        yield 'expired form' => [static fn (): Response => Pages::productController(new InMemoryProductRepository())->import([], Pages::request('POST', '/products/import', ['_token' => 'x']))];
        yield 'product not found' => [static fn (): Response => Pages::productController(new InMemoryProductRepository())->show(['id' => '9'], Pages::request('GET', '/products/9'))];
    }

    /**
     * @param Closure(): Response $render
     */
    #[Test]
    #[DataProvider('pages')]
    public function page_has_a_language_title_one_main_heading_and_landmarks(Closure $render): void
    {
        $page = Pages::parse($render());

        self::assertSame('en', Pages::one($page, 'html')->getAttribute('lang'));
        self::assertStringEndsWith('Product catalogue', Pages::text(Pages::one($page, 'title')));
        self::assertCount(1, $page->querySelectorAll('h1'));
        Pages::one($page, 'body > div > header');
        Pages::one($page, 'nav[aria-label="Main"]');
        Pages::one($page, 'main#main');
        Pages::one($page, 'body > div > footer');
    }

    /**
     * @param Closure(): Response $render
     */
    #[Test]
    #[DataProvider('pages')]
    public function skip_link_is_the_first_link_and_targets_the_main_content(Closure $render): void
    {
        $page = Pages::parse($render());

        $firstLink = $page->querySelector('a[href]');
        self::assertNotNull($firstLink);
        self::assertSame('#main', $firstLink->getAttribute('href'));
        self::assertSame('Skip to main content', Pages::text($firstLink));
    }

    /**
     * @param Closure(): Response $render
     */
    #[Test]
    #[DataProvider('pages')]
    public function headings_do_not_skip_levels(Closure $render): void
    {
        $page = Pages::parse($render());

        $previousLevel = 0;
        foreach ($page->querySelectorAll('h1, h2, h3, h4, h5, h6') as $heading) {
            $level = (int) substr($heading->localName, 1);
            self::assertLessThanOrEqual($previousLevel + 1, $level, 'Heading "' . Pages::text($heading) . '" skips a level.');
            $previousLevel = $level;
        }
    }

    /**
     * @param Closure(): Response $render
     */
    #[Test]
    #[DataProvider('pages')]
    public function every_form_control_has_a_label_and_existing_descriptions(Closure $render): void
    {
        $page = Pages::parse($render());

        $problems = [];
        foreach ($page->querySelectorAll('input:not([type="hidden"]), select, textarea') as $control) {
            $id = (string) $control->getAttribute('id');
            if ($id === '' || Pages::text($page->querySelector('label[for="' . $id . '"]')) === '') {
                $problems[] = 'A ' . $control->localName . " control ($id) has no label.";
            }
        }

        foreach ($page->querySelectorAll('[aria-describedby], [aria-labelledby]') as $element) {
            $references = trim($element->getAttribute('aria-describedby') . ' ' . $element->getAttribute('aria-labelledby'));
            foreach (explode(' ', $references) as $reference) {
                if ($page->getElementById($reference) === null) {
                    $problems[] = "Referenced id $reference does not exist.";
                }
            }
        }

        self::assertSame([], $problems);
    }

    /**
     * @param Closure(): Response $render
     */
    #[Test]
    #[DataProvider('pages')]
    public function ids_are_unique(Closure $render): void
    {
        $page = Pages::parse($render());

        $ids = Pages::attributes($page, '[id]', 'id');

        self::assertSame(array_values(array_unique($ids)), $ids);
    }

    /**
     * @param Closure(): Response $render
     */
    #[Test]
    #[DataProvider('pages')]
    public function every_image_has_alternative_text_and_every_link_and_button_has_a_name(Closure $render): void
    {
        $page = Pages::parse($render());

        foreach ($page->querySelectorAll('img') as $image) {
            self::assertTrue($image->hasAttribute('alt'), 'An image has no alt attribute: ' . $image->getAttribute('src'));
        }

        foreach ($page->querySelectorAll('a, button, summary') as $control) {
            self::assertNotSame('', Pages::text($control), 'A link or button has no text.');
        }
    }

    /**
     * @param Closure(): Response $render
     */
    #[Test]
    #[DataProvider('pages')]
    public function page_has_no_scripts_or_inline_styles(Closure $render): void
    {
        $page = Pages::parse($render());

        self::assertCount(0, $page->querySelectorAll('script, style, [style]'));
        foreach ($page->querySelectorAll('*') as $element) {
            foreach ($element->attributes as $attribute) {
                self::assertStringStartsNotWith('on', $attribute->localName, 'Event handler attribute on ' . $element->localName);
            }
        }
    }

    #[Test]
    public function different_pages_have_different_titles(): void
    {
        $titles = [];
        foreach (['start page', 'filled list', 'detail with images', 'import form', 'expired form', 'product not found'] as $name) {
            [$render] = iterator_to_array(self::pages())[$name];
            $titles[] = Pages::text(Pages::one(Pages::parse($render()), 'title'));
        }

        self::assertSame(array_values(array_unique($titles)), $titles);
    }
}
