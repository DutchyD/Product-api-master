<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Controller;

use MaxServ\App\Controller\IndexController;
use MaxServ\App\Tests\Support\Pages;
use MaxServ\Core\Render\TemplateRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndexController::class)]
#[UsesClass(TemplateRenderer::class)]
final class IndexControllerTest extends TestCase
{
    #[Test]
    public function startPageIntroducesTheCatalogueWithLinksToTheProductsAndTheImport(): void
    {
        $response = Pages::indexController()->index();
        $page = Pages::parse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Product catalogue', Pages::text(Pages::one($page, 'title')));
        self::assertSame('Browse and import products from dummyjson.com', Pages::text(Pages::one($page, 'h1')));
        self::assertSame('Browse products', Pages::text(Pages::one($page, 'main a[href="/products"]')));
        self::assertSame('Import products', Pages::text(Pages::one($page, 'main a[href="/products/import"]')));
    }

    #[Test]
    public function startPageMarksNoNavigationLinkAsCurrent(): void
    {
        $page = Pages::parse(Pages::indexController()->index());

        self::assertNull($page->querySelector('nav[aria-label="Main"] [aria-current]'));
    }
}
