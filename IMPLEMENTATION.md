# Implementation

[README.md](README.md) describes the assignment and the foundation it starts from. This document describes the implementation built on that foundation: a product catalogue that imports products from [dummyjson.com](https://dummyjson.com/products), stores them in MySQL, and shows them in a product list and detail pages.

The foundation's structure is kept. `public/index.php` is unchanged, the application code lives in `packages/app`, and infrastructure that is not specific to products lives in `packages/core`. No PHP framework is used; the code builds on the Symfony components, Guzzle and Twig that the foundation provides.

## Assignment criteria

| Priority | Criterion | Implementation |
| --- | --- | --- |
| Must | Built on the supplied foundation | The foundation's entry point, bootstrap, container, router, connection and template renderer are kept and extended. See [Architecture](#architecture). |
| Must | `composer.json` holds the dependencies and the autoloading of the code | The runtime dependencies are unchanged. The code autoloads through the `maxserv/core` and `maxserv/app` path packages (PSR-4). PHPUnit is a development dependency, and `autoload-dev` maps both test namespaces. |
| Must | The application starts with `docker compose up` | A one-shot `migrate` service creates the database tables before the `app` service starts. See [Getting started](#getting-started). |
| Must | The Apache document root is `/var/www/html/public` | Set through `APACHE_DOCUMENT_ROOT` in the `Dockerfile`, which also sends every path that does not match a file in `public` to `public/index.php`. |
| Must | A database table stores product information | `products`, with `product_tags` and `product_images`. See [Database](#database). |
| Must | A PHP import of at least 100 products from dummyjson.com | The import form on the product list imports up to 200 products per run, so one run can store every product dummyjson.com offers (194). See [Importing products](#importing-products). |
| Must | A browser overview from the database with title, price, brand and category | `GET /products` reads only from MySQL. See [Product list](#product-list). |
| Should | The normal price with the discount price | The normal price is struck through next to the discount price, calculated from the discount percentage. |
| Should | Prices formatted in euros | Dutch notation, for example `€ 1.234,56`. |
| Should | The thumbnail shown as an image | Every row of the list shows the product's thumbnail. |
| Could | Filter on category and/or brand | Dropdowns for every stored category and brand, which can be combined. |
| Could | Sort on all columns | Title, price, brand and category, in both directions. |
| Could | A detail page with all product information | `GET /products/{id}` shows the product's information from dummyjson.com. Reviews are not stored, so they are not shown. |

## Getting started
1. Run `composer install` to install the dependencies. The project requires PHP 8.5.
2. Run `docker compose up` to start the application. Add `--build` after changing the `Dockerfile`, and `-d` to run it in the background.

On every start, the `migrate` service waits until MySQL accepts connections, creates or updates the database tables with `bin/migrate`, and stops. The `app` service starts only after the migrations succeeded. When they fail, `docker compose logs migrate` shows why.

The application runs at `http://localhost:8080`, and phpMyAdmin at `http://localhost:8081`. Open `http://localhost:8080/products` and use the import form to load products from dummyjson.com.

The `app` container runs PHP 8.5.12 with Apache. PHP errors are written to the container log and never shown in responses.

## Architecture

### Request flow
`public/index.php` loads the autoloader and runs `MaxServ\Core\Bootstrap`. The bootstrap builds the dependency injection container from every `services.yaml` file under `packages`, and starts the `Router`, which reads the request.

The `Router` matches the request against `config/routes.yaml`, including its HTTP method. It calls the controller action with the route parameters and the `Request`. The request carries a session, which starts only when an action uses it; the session cookie is HttpOnly and SameSite=Lax, and Secure over HTTPS.

- When the action returns a `Response`, the router sends it. An action that writes its own output, such as the foundation's `IndexController`, keeps working.
- A path without a route gets a plain `404 Not Found`.
- A route called with a method it does not allow gets a plain `405 Method Not Allowed` with an `Allow` header.
- When creating the controller or running the action fails, for example because the database cannot be reached, the error is logged and the response is a plain `500 Internal Server Error` without details.

### Routes

| Method | Path | Action |
| --- | --- | --- |
| Any | `/` | The foundation's start page, with a link to the product list |
| GET, HEAD | `/products` | The product list, with the filter and import forms |
| GET, HEAD | `/products/{id}` | The product detail page; `id` is 1 to 10 digits |
| POST | `/products/import` | Imports products from dummyjson.com |

### Core package
The `core` package gains the infrastructure the feature needs:

- `Bootstrap::buildContainer()` builds the container without routing, so `bin/migrate` can use the same services.
- `Routing/Router` handles routing, sessions, responses and errors as described above.
- `Database/Connection` opens the MySQL connection with the `utf8mb4` character set, real prepared statements, exceptions on errors, and one SQL statement per call.
- `Database/MigrationRunner` applies the SQL migrations.
- `Logging/Logger` is a small error logger interface; `PhpErrorLogger` writes to the PHP error log.
- `Time/MonotonicClock` measures elapsed time for the import's time limits; `HrtimeClock` reads the system's monotonic timer.

### App package
The `app` package holds the product feature:

- `Product/`: the `Product` model with its `Dimensions` and `AvailabilityStatus`, the `ProductRepository` interface and its MySQL implementation, the `DecimalColumn` conversions between cents and `DECIMAL` values, and the list request, sort columns, sort directions, filter options and page of results that drive the product list.
- `Pagination/PageRequest`: the page number and page size.
- `Import/`: the dummyjson.com client, the mapper that validates a raw product, the importer, the import request and result, and the import exceptions.
- `Security/CsrfTokenManager`: the token that protects the import form.
- `Controller/ProductController`: the list, detail and import actions.

Templates live in `templates/`: a shared `layout.html.twig`, and in `templates/products/` the list, detail and not-found pages with macros for prices and list links.

## Products

### Product model
A product keeps the dummyjson.com id as its own id. It stores:

- the title, description and category;
- the price, discount percentage, rating and stock;
- the brand, which is optional;
- the SKU, weight and dimensions (width, height and depth);
- the warranty, shipping and availability information (`In Stock`, `Low Stock` or `Out of Stock`), the return policy and the minimum order quantity;
- the barcode, QR code URL and thumbnail URL;
- the creation and update dates from dummyjson.com, in UTC;
- its tags (trimmed, without duplicates) and image URLs, in their own tables, in their original order.

Reviews are not stored: no page needs them, and they hold reviewer names and email addresses.

Prices are kept in whole cents, so no float rounding can change them. The discount price is the price reduced by the discount percentage, rounded to whole cents with halves rounded up.

### Product list
`GET /products` shows the products in a table with the thumbnail, title, price, brand and category. The title links to the product's detail page. When a product has a discount, its normal price is struck through next to the discount price; otherwise only the normal price is shown. Prices are shown in euros in Dutch notation, without currency conversion. A product without a brand shows a dash.

The list is filtered, sorted and paginated with these query parameters:

| Parameter | Allowed values | Default |
| --- | --- | --- |
| `category` | A category of valid UTF-8, at most 100 characters | All categories |
| `brand` | A brand of valid UTF-8, at most 255 characters | All brands |
| `sort` | `title`, `price`, `brand` or `category` | Sorted by id |
| `direction` | `asc` or `desc` | `asc` |
| `page` | A whole number from 1 to 999999 | `1` |
| `perPage` | `10`, `20` or `50` | `20` |

A value that is missing or not allowed uses the default; an empty `category` or `brand` means all.

- **Filtering:** a filter form above the table has a dropdown with every stored category and one with every stored brand, in alphabetical order. A filter shows only the products whose category or brand equals the chosen value exactly, including case and accents. With both filters set, a product must match both. A link clears the filters.
- **Sorting:** the Title, Price, Brand and Category column headers are links. Clicking a header sorts by that column in ascending order, and clicking the sorted column's header again reverses the order. The sorted column shows an arrow for its direction. Price sorts by the price the customer pays: the discount price, or the normal price without a discount. Products without a brand come last in both directions. Products with the same value keep the order of their id.
- **Pagination:** the page shows the current page, the total number of pages and matching products, links to the previous and next page, and links to change the number of products per page. A page after the last page shows the last page.

The column header, pager and page-size links and the filter form keep the current filters, sorting and page size, and the link that clears the filters keeps the sorting and page size. Changing a filter, the sorting or the page size starts again at page 1. Without stored products, the page says so and shows the import form. When products exist but none match the filters, the page says so.

### Product detail
`GET /products/{id}` shows a product's title, description, prices, discount percentage, category, brand, rating, stock, availability, minimum order quantity, SKU, barcode, weight, dimensions, warranty, shipping and return information, tags, QR code, and creation and update dates, with its images; a product without images shows its thumbnail instead. An unknown id gets a `404 Not Found` page.

### Importing products
The import form on the product list posts to `POST /products/import` with:

| Field | Allowed values | Meaning |
| --- | --- | --- |
| `count` | 1 to 200 | How many products to import |
| `offset` | 0 to 10000 | How many dummyjson.com products to skip first |

The import fetches the products in pages of at most 100 and stores each page in one transaction. A product that already exists is updated, including its tags and images, so importing the same products again creates no duplicates. A product with missing or invalid data, or an entry that is not a product object, is skipped and counted, and the reason is logged. Afterwards the browser is redirected to the product list, which shows how many products were imported and skipped. When the offset is past the last dummyjson.com product, nothing is imported and the message says so.

Each product is validated before it is stored: types, ranges and lengths that fit the database columns, `https` URLs with a host for the thumbnail, images and QR code, ISO 8601 date-times that fall in the years 1000 to 9999 in UTC, a known availability status, and at most 20 tags and 20 images.

The form carries a token bound to the browser session. A request without a valid token gets a `403 Forbidden` page and imports nothing. An invalid `count` or `offset` imports nothing and shows the allowed values. When dummyjson.com cannot be reached, returns an invalid response, or takes longer than 8 seconds for a page, or when the products cannot be stored, the list shows that the import failed and the cause is logged. Pages stored before the failure are kept. The 8-second limit runs from the start of each request and is checked when the response headers arrive and between reads of the body.

## Database
The `products` table holds the scalar fields, with the dimensions and dates as columns. `product_tags` and `product_images` hold the tags and image URLs with their position, and are removed with their product. All tables use InnoDB and `utf8mb4`. Prices and other decimals are `DECIMAL` columns.

### Migrations
The SQL files in `database/migrations` create the tables. `bin/migrate` applies, in file name order, every file that has not been applied yet, and records each applied file in the `schema_migrations` table. Running it again applies nothing new.

Each file holds one SQL statement that is safe to run again, such as `CREATE TABLE IF NOT EXISTS`, because MySQL cannot undo a table change when recording it fails. A new migration is a new file with the next number.

## Security
- **SQL injection:** every value is a bound parameter of a real prepared statement, including `LIMIT` and `OFFSET`. The sort order comes from a fixed list of SQL expressions, never from the request. The connection does not allow several statements in one call.
- **Cross-site scripting:** Twig escapes all output, and product text from dummyjson.com is treated as untrusted. Images are only stored and shown with `https` URLs.
- **Cross-site request forgery:** the import accepts only POST requests with a token from the browser session, created from a cryptographic random source and compared in constant time.
- **Server-side request forgery:** the import only calls the fixed dummyjson.com URL, does not follow redirects, and keeps TLS certificate checks on. The request controls only two bounded numbers.
- **Denial of service:** the import is limited to 200 products in at most two requests. Each response is limited to 2 MiB, each page to 8 seconds (checked when the headers arrive and between reads), and each network wait to 4 seconds. JSON depth, tag and image counts, page sizes and page numbers are bounded too.
- **Error handling:** error responses contain no internal details. The router's 404, 405 and 500 responses are plain text, and an unknown product gets a not-found page. Causes are written to the PHP error log, without request bodies.
- **Personal data:** reviews, which contain reviewer names and email addresses, are never requested from dummyjson.com or stored.

There is no authentication: anyone who can reach the application can view the products and run an import. This suits a local application that only stores public data.

## PHP 8.5 and deprecations
The code uses the features of PHP 8.4 and 8.5 where they fit:

- **PHP 8.5:** the `Uri\Rfc3986\Uri` class for URL validation and building, the pipe operator, `#[\NoDiscard]` with `(void)` casts in the tests where a result is deliberately unused, `clone` with properties, and `array_first()`.
- **PHP 8.4:** `PDO::connect()` with the `Pdo\Mysql` class and its constants, the `RoundingMode` enum, `array_all()` and `array_any()`, and `new` without wrapping parentheses.
- **Earlier versions:** `#[\Override]` on interface implementations and typed class constants (8.3), readonly classes (8.2), and enums (8.1).

No deprecated PHP, MySQL or PHPUnit construct is used. Examples are the `PDO::MYSQL_ATTR_*` constants, `VALUES()` in upserts, `SQL_CALC_FOUND_ROWS`, the `utf8` charset alias, and PHPUnit doc-comment annotations. The test suite fails on any deprecation, including deprecations that libraries suppress.

## Testing
Run `vendor/bin/phpunit` to run the unit tests of both packages. The tests use no network and no database: the HTTP client, the repository and the clock are replaced by in-memory fakes. A test that triggers a deprecation, notice or warning fails, and the tests run in random order.

The tests cover the model and its price calculation, the decimal conversion, pagination, the list's query parameters and links, the import request, the mapper's validation, the client's limits and failures, the importer's batching, the CSRF token, the router, and the controller with its real templates.

The SQL of the MySQL repository and the migration runner need a real database. They are checked by running the application in Docker.

## Design decisions
- **Normalized tags and images:** separate tables keep tags and images in order and queryable, at the cost of a little more mapping code than JSON columns.
- **dummyjson.com id as the primary key:** dummyjson.com is the only source, so a separate generated id would add a column and a lookup for no gain. It also makes the import idempotent.
- **Prices in cents:** exact integer arithmetic in PHP and `DECIMAL` in MySQL avoid off-by-one-cent errors. The discount price gives the same result in PHP, with integer arithmetic, and in SQL, with `DECIMAL` arithmetic, so sorting matches what is shown.
- **An import form instead of a command line import:** it is easier to use. The CSRF token and the limits cover the main risk of an HTTP endpoint.
- **A streamed HTTP client with its own time limit:** streaming lets the 2 MiB limit stop the download, and an injected clock makes the time limit testable.
- **A small migration runner:** three SQL files do not need a migration library. The runner uses the foundation's Finder and PDO.
- **No new runtime dependencies:** everything is built on the packages the foundation provides; PHPUnit is the only addition, for development.

## Limitations
- There is no authentication or rate limiting beyond the import limits.
- Sessions are stored in the container's `/tmp`, so recreating the container, for example with `docker compose down` and `up`, invalidates an open import form; reloading the page fixes it.
- Prices from dummyjson.com are shown as euros without currency conversion.
- Images are shown from their dummyjson.com URLs and are not stored locally.
- Products removed from dummyjson.com are not removed locally.
- The 8-second limit per page is checked only when the response headers arrive and between reads, so a page can take one more 4-second wait. A server that sends its headers very slowly is limited only by the 4-second wait per network packet.
