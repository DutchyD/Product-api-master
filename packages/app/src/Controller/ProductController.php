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
    private const string IMPORT_PATH = '/products/import';
    private const string FLASH_SUCCESS = 'success';
    private const string NAV_PRODUCTS = 'products';
    private const string NAV_IMPORT = 'import';
    private const string MAX_ID_DIGITS_PATTERN = '/^\d{1,10}$/';
    private const int MAX_PRODUCT_ID = 4_294_967_295;
    private const int MAX_KEPT_VALUE_LENGTH = 32;
    private const string DEFAULT_IMPORT_COUNT = '200';
    private const string DEFAULT_IMPORT_OFFSET = '0';
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
     * Shows one page of the stored products, filtered and sorted as requested, with the filter form and the result of
     * the last import.
     *
     * @param array<string, mixed> $parameters The route parameters.
     * @param Request $request The request; its query may hold category, brand, sort, direction, page and perPage.
     *
     * @return Response The product list page.
     * @throws TwigError The template could not be rendered.
     * @throws \PDOException The products could not be read.
     * @throws \RuntimeException The session could not be started.
     * @throws LogicException The request session does not support flash messages.
     */
    public function list(array $parameters, Request $request): Response
    {
        $productPage = $this->productRepository->findPage(ProductListRequest::fromQuery($request->query->all()));

        return new Response($this->templateRenderer->render('products/list.html.twig', [
            'productPage' => $productPage,
            'listRequest' => $productPage->listRequest,
            'filterOptions' => $this->productRepository->findFilterOptions(),
            'sortColumns' => SortColumn::cases(),
            'perPageOptions' => PageRequest::PER_PAGE_OPTIONS,
            'successMessages' => $this->flashBag($request)->get(self::FLASH_SUCCESS),
            'activeNav' => self::NAV_PRODUCTS,
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
        $isStorableId = is_string($id)
            && preg_match(self::MAX_ID_DIGITS_PATTERN, $id) === 1
            && (int) $id <= self::MAX_PRODUCT_ID;
        $product = $isStorableId ? $this->productRepository->findById((int) $id) : null;

        if ($product === null) {
            return $this->errorPage(
                'Product not found',
                'No product has this id. It may not have been imported yet.',
                ['label' => 'Back to the products', 'href' => self::LIST_PATH, 'icon' => 'chevron-left'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new Response($this->templateRenderer->render('products/show.html.twig', [
            'product' => $product,
            'activeNav' => self::NAV_PRODUCTS,
            'activeNavIsPage' => false,
        ]));
    }

    /**
     * Shows the import form with its default values.
     *
     * @param array<string, mixed> $parameters The route parameters.
     * @param Request $request The request.
     *
     * @return Response The import page.
     * @throws TwigError The template could not be rendered.
     * @throws \Random\RandomException No random source is available to create the form token.
     * @throws \RuntimeException The session could not be started.
     */
    public function importForm(array $parameters, Request $request): Response
    {
        return $this->importPage(
            $request,
            [
                ImportRequest::FIELD_COUNT => self::DEFAULT_IMPORT_COUNT,
                ImportRequest::FIELD_OFFSET => self::DEFAULT_IMPORT_OFFSET,
            ],
            [],
            null,
            Response::HTTP_OK,
        );
    }

    /**
     * Imports products from dummyjson.com when the form token and fields are valid, then redirects to the product
     * list; otherwise shows why nothing or not everything was imported.
     *
     * @param array<string, mixed> $parameters The route parameters.
     * @param Request $request The request; its form fields are _token, count and offset.
     *
     * @return Response A 303 redirect to the product list; the expired-form page with status 403 when the token is
     *                  not valid; or the import page again with status 422 for invalid fields or 503 when the import
     *                  failed.
     * @throws TwigError The template could not be rendered.
     * @throws \Random\RandomException No random source is available to create the form token.
     * @throws \RuntimeException The session could not be started.
     * @throws LogicException The request session does not support flash messages.
     */
    public function import(array $parameters, Request $request): Response
    {
        $input = $request->request->all();
        if (!$this->csrfTokenManager->isValid($request->getSession(), $input['_token'] ?? null)) {
            return $this->errorPage(
                'This form has expired',
                'The import form is no longer valid, so nothing was imported. '
                    . 'Open the form again and submit it once more.',
                ['label' => 'Open the import form again', 'href' => self::IMPORT_PATH, 'icon' => 'arrow-down-tray'],
                Response::HTTP_FORBIDDEN,
            );
        }

        try {
            $importRequest = ImportRequest::fromInput($input);
        } catch (InvalidImportRequest $exception) {
            return $this->importPage(
                $request,
                $this->keptValues($input),
                $exception->fieldErrors(),
                null,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $result = $this->productImporter->import($importRequest);
        } catch (ImportFailed $exception) {
            $this->logger->error(
                'Product import failed: ' . $exception->getMessage() . ' ' . $exception->getPrevious()?->getMessage(),
            );

            return $this->importPage(
                $request,
                $this->keptValues($input),
                [],
                self::IMPORT_FAILED_MESSAGE,
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $this->logSkippedProducts($result);
        $this->flashBag($request)->add(self::FLASH_SUCCESS, $this->describe($result));

        return new RedirectResponse(self::LIST_PATH, Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function importPage(Request $request, array $values, array $errors, ?string $failure, int $status): Response
    {
        return new Response($this->templateRenderer->render('products/import.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'failure' => $failure,
            'csrfToken' => $this->csrfTokenManager->tokenFor($request->getSession()),
            'limits' => [
                'minCount' => ImportRequest::MIN_COUNT,
                'maxCount' => ImportRequest::MAX_COUNT,
                'minOffset' => ImportRequest::MIN_OFFSET,
                'maxOffset' => ImportRequest::MAX_OFFSET,
            ],
            'activeNav' => self::NAV_IMPORT,
        ]), $status);
    }

    /**
     * @param array{label: string, href: string, icon: string} $action
     */
    private function errorPage(string $title, string $text, array $action, int $status): Response
    {
        return new Response($this->templateRenderer->render('error.html.twig', [
            'title' => $title,
            'text' => $text,
            'action' => $action,
            'activeNav' => null,
        ]), $status);
    }

    /**
     * Returns the submitted values to show again: short texts as entered, anything else as an empty field.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function keptValues(array $input): array
    {
        $keep = static fn (mixed $value): string =>
            is_string($value) && mb_strlen($value) <= self::MAX_KEPT_VALUE_LENGTH ? $value : '';

        return [
            ImportRequest::FIELD_COUNT => $keep($input[ImportRequest::FIELD_COUNT] ?? null),
            ImportRequest::FIELD_OFFSET => $keep($input[ImportRequest::FIELD_OFFSET] ?? null),
        ];
    }

    private function describe(ImportResult $result): string
    {
        if ($result->offsetPastTotal) {
            return 'No products imported: the offset is past the last of the '
                . "{$result->sourceTotal} dummyjson.com products.";
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
