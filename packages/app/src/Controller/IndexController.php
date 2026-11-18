<?php

declare(strict_types=1);

namespace MaxServ\App\Controller;

use MaxServ\Core\Render\TemplateRenderer;
use Symfony\Component\HttpFoundation\Response;
use Twig\Error\Error as TwigError;

/**
 * Shows the start page.
 */
readonly class IndexController
{
    /**
     * Creates the controller.
     *
     * @param TemplateRenderer $templateRenderer Renders the page.
     */
    public function __construct(
        private TemplateRenderer $templateRenderer
    ) {
    }

    /**
     * Shows the start page, which introduces the catalogue and links to the products and the import.
     *
     * @return Response The start page.
     * @throws TwigError The template could not be rendered.
     */
    public function index(): Response
    {
        return new Response($this->templateRenderer->render('index.html.twig', []));
    }
}
