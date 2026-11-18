<?php

declare(strict_types=1);

namespace MaxServ\App\Controller;

use LogicException;
use MaxServ\App\Import\ImportFailed;
use MaxServ\App\Import\ImportRequest;
use MaxServ\App\Import\ImportResult;
use MaxServ\App\Import\InvalidImportRequest;
use MaxServ\App\Import\ProductImporter;
use MaxServ\App\Pagination\PageRequest;
use MaxServ\App\Product\ProductListRequest;
use MaxServ\App\Product\ProductRepository;
use MaxServ\App\Product\SortColumn;
use MaxServ\App\Security\CsrfTokenManager;
use MaxServ\Core\Logging\Logger;
use MaxServ\Core\Render\TemplateRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Twig\Error\Error as TwigError;

/**
 * Shows the stored products and imports products from dummyjson.com.
 */
final readonly class ProductController
{
    private const string LIST_PATH = '/products';
    private const string FLASH_SUCCESS = 'success';
    private const string FLASH_ERROR = 'error';
    private const string MAX_ID_DIGITS_PATTERN = '/^\d{1,10}$/';
    private const int MAX_PRODUCT_ID = 4_294_967_295;
    private const string IMPORT_FAILED_MESSAGE = 'The import failed: dummyjson.com could not be reached, '
        . 'returned an invalid response, or the products could not be stored. '
        . 'Products stored before the failure are kept.';

    /**
     * Creates the controller.
     *
     * @param TemplateRenderer $templateRenderer Renders the pages.
     * @param ProductRepository $productRepository Finds the stored products.
     * @param ProductImporter $productImporter Imports products from dummyjson.com.
     * @param CsrfTokenManager $csrfTokenManager Creates and checks the import form token.
     * @param Logger $logger Records failed imports and skipped products.
     */
    public function __construct(
        private TemplateRenderer $templateRenderer,
        private ProductRepository $productRepository,
        private ProductImporter $productImporter,
        private CsrfTokenManager $csrfTokenManager,
        private Logger $logger,
    ) {
    }

    /**
     * Shows one page of the stored products, filtered and sorted as requested, with the filter and import forms and
     * the result of the last import.
     *
     * @param array<string, mixed> $parameters The route parameters.
     * @param Request $request The request; its query may hold category, brand, sort, direction, page and perPage.
     *
     * @return Response The product list page.
     * @throws TwigError The template could not be rendered.
     * @throws \PDOException The products could not be read.
     * @throws \Random\RandomException No random source is available to create the form token.
     * @throws \RuntimeException The session could not be started.
     * @throws LogicException The request session does not support flash messages.
     */
    public function list(array $parameters, Request $request): Response
    {
        $productPage = $this->productRepository->findPage(ProductListRequest::fromQuery($request->query->all()));
        $flashBag = $this->flashBag($request);

        return new Response($this->templateRenderer->render('products/list.html.twig', [
            'productPage' => $productPage,
            'listRequest' => $productPage->listRequest,
            'filterOptions' => $this->productRepository->findFilterOptions(),
            'sortColumns' => SortColumn::cases(),
            'perPageOptions' => PageRequest::PER_PAGE_OPTIONS,
            'csrfToken' => $this->csrfTokenManager->tokenFor($request->getSession()),
            'successMessages' => $flashBag->get(self::FLASH_SUCCESS),
            'errorMessages' => $flashBag->get(self::FLASH_ERROR),
            'importLimits' => [
                'minCount' => ImportRequest::MIN_COUNT,
                'maxCount' => ImportRequest::MAX_COUNT,
                'minOffset' => ImportRequest::MIN_OFFSET,
                'maxOffset' => ImportRequest::MAX_OFFSET,
            ],
        ]));
    }

    /**
     * Shows one stored product, or a not-found page when no product has the id.
     *
     * @param array<string, mixed> $parameters The route parameters; id is the product id.
     * @param Request $request The request.
     *
     * @return Response The product page, or the not-found page with status 404.
     * @throws TwigError The template could not be rendered.
     * @throws \PDOException The product could not be read.
     */
    public function show(array $parameters, Request $request): Response
    {
        $id = $parameters['id'] ?? null;
        $product = is_string($id) && preg_match(self::MAX_ID_DIGITS_PATTERN, $id) === 1 && (int) $id <= self::MAX_PRODUCT_ID
            ? $this->productRepository->findById((int) $id)
            : null;

        if ($product === null) {
            return new Response(
                $this->templateRenderer->render('products/not_found.html.twig', []),
                Response::HTTP_NOT_FOUND,
            );
        }

        return new Response($this->templateRenderer->render('products/show.html.twig', ['product' => $product]));
    }

    /**
     * Imports products from dummyjson.com when the form token is valid, then redirects to the product list.
     *
     * @param array<string, mixed> $parameters The route parameters.
     * @param Request $request The request; its form fields are _token, count and offset.
     *
     * @return Response A 303 redirect to the product list, or a 403 response when the token is not valid.
     * @throws \RuntimeException The session could not be started.
     * @throws LogicException The request session does not support flash messages.
     */
    public function import(array $parameters, Request $request): Response
    {
        $input = $request->request->all();
        if (!$this->csrfTokenManager->isValid($request->getSession(), $input['_token'] ?? null)) {
            return new Response('Forbidden: the form has expired. Reload the product list and try again.', Response::HTTP_FORBIDDEN, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $flashBag = $this->flashBag($request);

        try {
            $result = $this->productImporter->import(ImportRequest::fromInput($input));
            $this->logSkippedProducts($result);
            $flashBag->add(self::FLASH_SUCCESS, $this->describe($result));
        } catch (InvalidImportRequest $exception) {
            $flashBag->add(self::FLASH_ERROR, $exception->getMessage());
        } catch (ImportFailed $exception) {
            $this->logger->error('Product import failed: ' . $exception->getMessage() . ' ' . $exception->getPrevious()?->getMessage());
            $flashBag->add(self::FLASH_ERROR, self::IMPORT_FAILED_MESSAGE);
        }

        return new RedirectResponse(self::LIST_PATH, Response::HTTP_SEE_OTHER);
    }

    private function describe(ImportResult $result): string
    {
        if ($result->offsetPastTotal) {
            return "No products imported: the offset is past the last of the {$result->sourceTotal} dummyjson.com products.";
        }

        return "Imported {$result->importedCount} products and skipped {$result->skippedCount()} with invalid data.";
    }

    private function logSkippedProducts(ImportResult $result): void
    {
        foreach ($result->skippedReasons as $reason) {
            $this->logger->error("Product import skipped a product: $reason");
        }
    }

    private function flashBag(Request $request): FlashBagInterface
    {
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            throw new LogicException('The request session does not support flash messages.');
        }

        return $session->getFlashBag();
    }
}
