# Implementation

[README.md](README.md) describes the assignment and the foundation it starts from. This document describes the implementation built on that foundation: a product catalogue that imports products from [dummyjson.com](https://dummyjson.com/products), stores them in MySQL, and shows them in a product list and detail pages.

The foundation's structure is kept. `public/index.php` is unchanged, the application code lives in `packages/app`, and infrastructure that is not specific to products lives in `packages/core`. No PHP framework is used; the code builds on the Symfony components, Guzzle and Twig that the foundation provides. The pages are styled with Tailwind CSS, are responsive from 320 pixels wide upwards, and meet WCAG 2.2 level AA.

## Assignment criteria

| Priority | Criterion | Implementation |
| --- | --- | --- |
| Must | Built on the supplied foundation | The foundation's entry point, bootstrap, container, router, connection and template renderer are kept and extended. See [Architecture](#architecture). |
| Must | `composer.json` holds the dependencies and the autoloading of the code | The runtime dependencies are unchanged. The code autoloads through the `maxserv/core` and `maxserv/app` path packages (PSR-4). PHPUnit and PHP_CodeSniffer are development dependencies, and `autoload-dev` maps both test namespaces. |
| Must | The application starts with `docker compose up` | A one-shot `migrate` service creates the database tables and a one-shot `assets` service builds the stylesheet before the `app` service starts. See [Getting started](#getting-started). |
| Must | The Apache document root is `/var/www/html/public` | Set through `APACHE_DOCUMENT_ROOT` in the `Dockerfile`, which also sends every path that does not match a file in `public` to `public/index.php`. |
| Must | A database table stores product information | `products`, with `product_tags` and `product_images`. See [Database](#database). |
| Must | A PHP import of at least 100 products from dummyjson.com | The import page imports up to 200 products per run, so one run can store every product dummyjson.com offers (194). See [Importing products](#importing-products). |
| Must | A browser overview from the database with title, price, brand and category | `GET /products` reads only from MySQL. See [Product list](#product-list). |
| Should | The normal price with the discount price | The normal price is struck through next to the discount price, calculated from the discount percentage, with a badge showing the discount. |
| Should | Prices formatted in euros | Dutch notation, for example `€ 1.234,56`. |
| Should | The thumbnail shown as an image | Every product in the list shows its thumbnail. |
| Could | Filter on category and/or brand | Dropdowns for every stored category and brand, which can be combined. |
| Could | Sort on all columns | Every column header of the table sorts on title, price, brand or category, in both directions. On phones, a sort menu offers the same orders. |
| Could | A detail page with all product information | `GET /products/{id}` shows the product's information from dummyjson.com. Reviews are not stored, so they are not shown. |

## Getting started
1. Run `composer install` to install the dependencies. The project requires PHP 8.5.
2. Run `docker compose up` to start the application. Add `--build` after changing the `Dockerfile`, and `-d` to run it in the background. The images are built with BuildKit, the default builder of Docker Engine 23 and later and of Docker Desktop; the `Dockerfile` names its syntax version, so BuildKit uses a current Dockerfile frontend.

On every start, two one-shot services run before the `app` service starts, and the `app` service starts only after both succeeded:

- `migrate` waits until MySQL accepts connections, creates or updates the database tables with `bin/migrate`, and stops. When it fails, `docker compose logs migrate` shows why.
- `assets` builds the stylesheet from `assets/styles/app.css` and the templates with the Tailwind CSS command line tool, and stops. When it fails, `docker compose logs assets` shows why.

The application runs at `http://localhost:8080`, and phpMyAdmin at `http://localhost:8081`. Open `http://localhost:8080`, choose **Import products**, and import products from dummyjson.com.

While changing templates or styles, run `docker compose run --rm assets tailwindcss -i assets/styles/app.css -o public/assets/app.css --watch=always` next to the running application. It rebuilds the stylesheet on every change until it is stopped.

The `app` container runs PHP 8.5.12 with Apache. PHP errors are written to the container log and never shown in responses.

## Architecture

### Request flow
`public/index.php` loads the autoloader and runs `MaxServ\Core\Bootstrap`. The bootstrap builds the dependency injection container from every `services.yaml` file under `packages`, and starts the `Router`, which reads the request.

The `Router` matches the request against `config/routes.yaml`, including its HTTP method. It calls the controller action with the route parameters and the `Request`. The request carries a session, which starts only when an action uses it; the session cookie is HttpOnly and SameSite=Lax, and Secure over HTTPS.

- When the action returns a `Response`, the router sends it. An action that writes its own output instead keeps working.
- A path without a route gets a plain `404 Not Found`.
- A route called with a method it does not allow gets a plain `405 Method Not Allowed` with an `Allow` header.
- When creating the controller or running the action fails, for example because the database cannot be reached, the error is logged and the response is a plain `500 Internal Server Error` without details.

### Routes

| Method | Path | Action |
| --- | --- | --- |
| Any | `/` | The start page, with links to the products and the import |
| GET, HEAD | `/products` | The product list, with the filter form |
| GET, HEAD | `/products/{id}` | The product detail page; `id` is 1 to 10 digits |
| GET, HEAD | `/products/import` | The import form |
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
- `Import/`: the dummyjson.com client, the mapper that validates a raw product, the importer, the import request with its field errors, the import result, and the import exceptions.
- `Security/CsrfTokenManager`: the token that protects the import form.
- `Controller/IndexController`: the start page.
- `Controller/ProductController`: the list, detail, import form and import actions.

### Front end
The pages are server-rendered Twig templates in `templates/`, styled with Tailwind CSS. They use no JavaScript, no `style` attributes and no `<style>` elements.

- `templates/layout.html.twig` is the page shell of every page: the skip link, the header with the logo and main navigation, the main content area and the footer.
- `templates/components/` holds the reusable components. A component exists only when two or more pages or sections use it:

  | Component | Used for |
  | --- | --- |
  | `button` | Links and submit buttons in the primary (gradient) and secondary (glass) style |
  | `panel` | The translucent glass surface around content blocks |
  | `badge` | Categories, tags, discounts and availability |
  | `icon` | The inline SVG icons, hidden from assistive technology |
  | `price` | Euro amounts, and a product's price with its discount |
  | `thumbnail` | Product images with their gradient ring |
  | `form_field` | A labelled input or select with its hint and error |
  | `alert` | Success and error messages |
  | `notice` | Empty states and error pages, with a title, text and an action |
  | `links` | Product list links that keep the current filters and sorting |

- The pages are `index.html.twig` (the start page), `products/list.html.twig`, `products/show.html.twig`, `products/import.html.twig` and `error.html.twig`, which shows the not-found and expired-form pages.
- `assets/styles/app.css` is the stylesheet source: it loads Tailwind CSS, defines the design tokens of [Visual design](#visual-design), the font, the focus style and the reduced-motion rules, and lists `templates/` as the only place to look for class names. Templates always write class names in full, so the build finds every class.
- The `assets` service builds the minified stylesheet with the standalone Tailwind CSS command line tool, without Node.js. The stylesheet is written to the `assets` Docker volume, which the `app` service serves read-only at `/assets/app.css`. It is never committed.
- The Plus Jakarta Sans font is served from `public/fonts/`, with its license, so pages make no requests to font services. Product images and QR codes from dummyjson.com are the only content loaded from another host.
- The icons are a subset of Heroicons, kept with their license in `templates/components/`.

## Visual design
The pages use a soft, light style: a pastel gradient background from lavender through pink to peach with a few blurred colour orbs, content on translucent white glass panels with large rounded corners and violet-tinted shadows, pill-shaped buttons and badges, and small uppercase letter-spaced labels above headings. Primary actions use a magenta-to-violet gradient, and price highlights use a deep indigo-to-violet gradient.

Every colour pair meets WCAG 2.2 level AA on every background it is used on. The lowest contrast below is the worst case: text directly on the page counts the blurred colour orbs behind it at their full strength, and text on panels counts glass panels over every page and orb colour.

| Use | Colour | Lowest contrast |
| --- | --- | --- |
| Body text and headings | indigo `#1e1b4b` | 11.2:1 |
| Secondary text, hints, struck-through prices | slate indigo `#4a4e7a` | 5.5:1 |
| Labels above headings, focus ring on light surfaces | violet `#6d28d9` | 5.0:1 |
| Links | violet `#5b21b6` | 6.3:1 |
| Form control borders | slate `#64748b` | 3.3:1 |
| Field error text, on the form panel | rose `#be123c` | 5.8:1 |
| White text on the primary gradient | fuchsia `#a21caf` to violet `#6d28d9` | 6.3:1 |
| White text and focus ring on the highlight gradient, including its orbs | indigo `#312e81` to violet `#6d28d9` | 5.4:1 |
| Category and tag badges | violet `#5b21b6` on `#ede9fe` | 7.5:1 |
| Discount and out-of-stock badges | rose `#be123c` on `#fff1f2` | 5.7:1 |
| In-stock badge | emerald `#065f46` on `#ecfdf5` | 7.2:1 |
| Low-stock badge | amber `#92400e` on `#fffbeb` | 6.8:1 |

Text is set in Plus Jakarta Sans, falling back to the system's sans-serif font.

The layout adapts at three widths: phones below 768 pixels, tablets from 768 pixels, and desktops from 1024 pixels. Content is limited to 80 rem wide and centred. The header is not fixed to the top of the screen, so it never covers focused content.

## Accessibility
The pages meet WCAG 2.2 level AA:

- **Structure:** every page has `lang="en"`, a unique title, one `h1`, headings in order, and `header`, `nav`, `main` and `footer` landmarks. A "Skip to main content" link is the first item to receive focus.
- **Keyboard:** everything works with a keyboard alone, in reading order. Every focusable element shows a 2-pixel focus ring with a 2-pixel offset: violet on light surfaces, and white on the highlight gradient of the start page.
- **Targets:** links and buttons are at least 24 by 24 pixels, apart from links inside a sentence. Buttons, the navigation, the sort menu, and the page and page-size links are at least 44 pixels high.
- **Text:** text meets the contrast in [Visual design](#visual-design), can be zoomed to 200 percent, and survives increased letter, word and line spacing without being cut off.
- **Reflow:** at 320 pixels wide, every page fits without horizontal scrolling.
- **Images:** product images on the detail page have alternative text naming the product and the image number. List thumbnails sit next to the product title, so they are marked decorative.
- **Information not by colour alone:** discounts show the percentage and the struck-through original price, availability is spelled out, and the sorted column shows an arrow and states its order.
- **Names and states:** the current page of the navigation, the breadcrumb, the pagination and the page size are marked as current. On a product page, the Products link is marked as the current section rather than the current page. Sortable column headers state their sort order. Sort links have names that start with their visible text, and page links have names that contain it, such as "Page 5".
- **Forms:** every field has a visible label, and the import fields also have a hint with their allowed values. Errors appear next to the field and in a summary at the top, are linked to the field, and keep the entered values. Changing a dropdown never submits a form by itself.
- **Messages:** success messages are announced as status messages, and errors as alerts.
- **Motion:** the pages have no animations other than short colour transitions and the turning arrow of the sort menu, which all stop when the system asks for reduced motion.

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

### Start page
`/` introduces the catalogue with a heading, a short description and two buttons: **Browse products** opens the product list and **Import products** opens the import page. Below it, three short panels describe importing, filtering and sorting, and the product details.

### Product list
`GET /products` shows the stored products under a `Products` heading, with a line such as "Showing 21–40 of 194 products". When products are stored, a filter form sits above the list.

On tablets and desktops, the products are a table with the thumbnail, title, price, brand and category. On phones, each product is a card with the same information, and a sort menu sits above the cards. The title links to the product's detail page. When a product has a discount, the discount price is shown next to the struck-through normal price and a badge with the discount percentage; otherwise only the normal price is shown. Prices are shown in euros in Dutch notation, without currency conversion. A product without a brand shows a dash, which assistive technology reads as "No brand".

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

- **Filtering:** the filter form has a dropdown with every stored category and one with every stored brand, in alphabetical order, and an **Apply filters** button. A filter shows only the products whose category or brand equals the chosen value exactly, including case and accents. With both filters set, a product must match both. When a filter is set, a **Clear filters** link removes both.
- **Sorting:** the Title, Price, Brand and Category column headers are links. Clicking a header sorts by that column in ascending order, and clicking the sorted column's header again reverses the order. The sorted column shows an arrow for its direction. On phones, the sort menu lists every column in both directions and marks the current order. Price sorts by the price the customer pays: the discount price, or the normal price without a discount. Products without a brand come last in both directions. Products with the same value keep the order of their id.
- **Pagination:** below the list, links lead to the previous and next page and to the page numbers: the first and last page, and the pages around the current one, with gaps shown as an ellipsis. On phones, only the previous and next links and "Page 2 of 10" are shown. Links set the page size to 10, 20 or 50. A page after the last page shows the last page.

The column header, pagination and page-size links and the filter form keep the current filters, sorting and page size, and the **Clear filters** link keeps the sorting and page size. Changing a filter, the sorting or the page size starts again at page 1.

Without stored products, the page shows "No products yet" with a button to the import page. When products exist but none match the filters, it shows "No products match the filters" with a button that clears them. After an import, the list shows its result as a message above the products.

### Product detail
`GET /products/{id}` shows a breadcrumb back to the products, the product's images, and its information. On desktops the images and the information sit side by side; on phones and tablets they are stacked.

- **Images:** the first image is shown large, and the other images as smaller tiles below it. A product without images shows its thumbnail instead.
- **Information:** the category and availability badges, the title, the brand (or "No brand"), and the rating as five stars with its value written out, for example "Rated 2,56 out of 5".
- **Price:** a highlighted panel with the discount price, the struck-through normal price and the discount percentage when there is a discount, the stock and the minimum order quantity.
- **Description, tags and specifications:** the description; the tags as badges; and the SKU, barcode, weight, dimensions, warranty, shipping and return information, and the creation and update dates at dummyjson.com; and the QR code.

An unknown id gets a `404 Not Found` page that says "Product not found" and links back to the products.

### Importing products
`GET /products/import` shows the import form. It has two fields, each with a visible label and a hint with the allowed values. The browser's own form validation is switched off, so every error is shown the same way, by the application:

| Field | Allowed values | Default | Meaning |
| --- | --- | --- | --- |
| `count` | 1 to 200 | 200 | How many products to import |
| `offset` | 0 to 10000 | 0 | How many dummyjson.com products to skip first |

The form posts to `POST /products/import`:

- **Success:** the import fetches the products in pages of at most 100 and stores each page in one transaction. A product that already exists is updated, including its tags and images, so importing the same products again creates no duplicates. A product with missing or invalid data, or an entry that is not a product object, is skipped and counted, and the reason is logged. The browser is then redirected to the product list, which shows how many products were imported and skipped. When the offset is past the last dummyjson.com product, nothing is imported and the message says so.
- **Invalid input:** when `count` or `offset` is missing, not a whole number, or out of range, nothing is imported. The form is shown again with status `422 Unprocessable Content`, the entered values, a summary of the errors at the top, and each error next to its field, for example "Enter a whole number from 1 to 200."
- **Expired form:** the form carries a token bound to the browser session. A request without a valid token imports nothing and gets a `403 Forbidden` page that says the form has expired and links back to the import form.
- **Failure:** when dummyjson.com cannot be reached, returns an invalid response, or takes longer than 8 seconds for a page, or when the products cannot be stored, the form is shown again with status `503 Service Unavailable`, the entered values, and a message that the import failed. The cause is logged. Pages stored before the failure are kept. The 8-second limit runs from the start of each request and is checked when the response headers arrive and between reads of the body.

Each product is validated before it is stored: types, ranges and lengths that fit the database columns, `https` URLs with a host for the thumbnail, images and QR code, ISO 8601 date-times that fall in the years 1000 to 9999 in UTC, a known availability status, and at most 20 tags and 20 images.

## Database
The `products` table holds the scalar fields, with the dimensions and dates as columns. `product_tags` and `product_images` hold the tags and image URLs with their position, and are removed with their product. All tables use InnoDB and `utf8mb4`. Prices and other decimals are `DECIMAL` columns.

### Migrations
The SQL files in `database/migrations` create the tables. `bin/migrate` applies, in file name order, every file that has not been applied yet, and records each applied file in the `schema_migrations` table. Running it again applies nothing new.

Each file holds one SQL statement that is safe to run again, such as `CREATE TABLE IF NOT EXISTS`, because MySQL cannot undo a table change when recording it fails. A new migration is a new file with the next number.

## Security
- **SQL injection:** every value is a bound parameter of a real prepared statement, including `LIMIT` and `OFFSET`. The sort order comes from a fixed list of SQL expressions, never from the request. The connection does not allow several statements in one call.
- **Cross-site scripting:** Twig escapes all output, including the values kept in the import form, and product text from dummyjson.com is treated as untrusted. Images are only stored and shown with `https` URLs. The pages contain no scripts, no `style` attributes and no `<style>` elements.
- **Cross-site request forgery:** the import accepts only POST requests with a token from the browser session, created from a cryptographic random source and compared in constant time. Showing the import form changes nothing.
- **Server-side request forgery:** the import only calls the fixed dummyjson.com URL, does not follow redirects, and keeps TLS certificate checks on. The request controls only two bounded numbers.
- **Denial of service:** the import is limited to 200 products in at most two requests. Each response is limited to 2 MiB, each page to 8 seconds (checked when the headers arrive and between reads), and each network wait to 4 seconds. JSON depth, tag and image counts, page sizes and page numbers are bounded too.
- **Error handling:** error responses contain no internal details. The router's 404, 405 and 500 responses are plain text, and the application's not-found and expired-form pages show only a short explanation. Causes are written to the PHP error log, without request bodies.
- **Personal data:** reviews, which contain reviewer names and email addresses, are never requested from dummyjson.com or stored. The font is served by the application, so no font service learns who visits.
- **Build tooling:** the Tailwind CSS command line tool is downloaded at a fixed version and checked against its published SHA-256 checksum before the image is built. It reads the project read-only and writes only the stylesheet volume.
- **Continuous integration:** the workflow has read-only repository access and no secrets, pins its actions to commit SHAs, and checks Composer against its checksum. See [Continuous integration](#continuous-integration).

There is no authentication: anyone who can reach the application can view the products and run an import. This suits a local application that only stores public data.

## PHP 8.5 and deprecations
The code uses the features of PHP 8.4 and 8.5 where they fit:

- **PHP 8.5:** the `Uri\Rfc3986\Uri` class for URL validation and building, the pipe operator, `#[\NoDiscard]` with `(void)` casts in the tests where a result is deliberately unused, `clone` with properties, and `array_first()`.
- **PHP 8.4:** `PDO::connect()` with the `Pdo\Mysql` class and its constants, the `RoundingMode` enum, `array_all()` and `array_any()`, and `new` without wrapping parentheses.
- **Earlier versions:** `#[\Override]` on interface implementations and typed class constants (8.3), readonly classes (8.2), and enums (8.1).

No deprecated PHP, MySQL or PHPUnit construct is used. Examples are the `PDO::MYSQL_ATTR_*` constants, `VALUES()` in upserts, `SQL_CALC_FOUND_ROWS`, the `utf8` charset alias, and PHPUnit doc-comment annotations. The test suite fails on any deprecation, including deprecations that libraries suppress.

## Testing
Run `composer test` to run the unit tests of both packages. Test methods have camelCase names that state the behaviour, such as `priceSortUsesTheDiscountPrice`. The tests use no network and no database: the HTTP client, the repository and the clock are replaced by in-memory fakes. A test that triggers a deprecation, notice or warning fails, and the tests run in random order.

The tests cover the model and its price calculation, the decimal conversion, pagination, the list's query parameters and links, the import request and its field errors, the mapper's validation, the client's limits and failures, the importer's batching, the CSRF token, the router, and the controllers with their real templates.

The page tests render every page and state with the real templates and check the accessibility rules that markup can show: the language, title, single `h1`, landmarks and skip link; headings in order; a label for every field, and descriptions and labels that point at existing elements; unique ids; alternative text on every image and a name on every link and button; the absence of scripts, event-handler attributes, `style` attributes and `<style>` elements; and that the markup parses without HTML5 tree errors. The controller tests check the error and current-state attributes, such as `aria-invalid`, `aria-current` and `aria-sort`. Tests find products and controls by `data-` attributes and accessible names, not by styling classes, so restyling does not break them.

The SQL of the MySQL repository and the migration runner need a real database, and colour contrast, layout at each width, zoom and keyboard use need a browser. They are checked by running the application in Docker.

## Linting
Run `composer lint` to check the code style. It runs PHP_CodeSniffer with the PSR-12 standard, configured in `phpcs.xml.dist`, over `bin/migrate`, `public/index.php`, and the `src` and `tests` folders of both packages.

The code has no errors and no warnings, and the command fails on any error or warning. Methods, including test methods, have camelCase names, and lines are at most 120 characters long. `vendor/bin/phpcbf` fixes the violations that can be fixed automatically, such as spacing and missing final newlines.

PSR-1's side-effects rule is switched off for exactly two files: `public/index.php` and `bin/migrate`. Each must define `APPLICATION_ROOT` before it runs the bootstrap, so each both declares a symbol and runs code, which is what entry scripts do; `public/index.php` also stays as the foundation provides it.

## Continuous integration
A GitHub Actions workflow, `.github/workflows/ci.yml`, runs on every push to `main` and on every pull request. It uses Ubuntu 26.04, PHP 8.5 and Composer 2.11.2. A new run for the same pull request cancels the one still running, while every push to `main` gets a complete run. It has three jobs, each reported as its own check:

| Check | What it runs | It fails when |
| --- | --- | --- |
| Lint | `composer validate --strict --no-check-all --no-check-publish`, then `composer install` and `composer lint` | `composer.json` is invalid or `composer.lock` is out of date, or PHP_CodeSniffer reports any error or warning |
| Tests | `composer install` and `composer test` | A test fails, or triggers a deprecation, notice or warning |
| Dependencies | `composer audit --locked` | A locked package has a known security advisory or is abandoned |

`composer validate` skips two checks: unbounded or overly strict version constraints, because the local `maxserv/core` and `maxserv/app` packages are required with `*`; and suitability for publishing on Packagist, because the project is an application, not a published package. Every other warning fails the check.

The workflow gets read-only access to the repository and no secrets. The checkout does not keep the access token, every action is pinned to a full commit SHA with its version noted next to it, Composer is pinned to a version and checked against its SHA-256 checksum, and each job stops after 10 minutes. The runner, the time limit and the setup steps are defined once and reused by every job, so the three checks always run in the same environment.

## Design decisions
- **Normalized tags and images:** separate tables keep tags and images in order and queryable, at the cost of a little more mapping code than JSON columns.
- **dummyjson.com id as the primary key:** dummyjson.com is the only source, so a separate generated id would add a column and a lookup for no gain. It also makes the import idempotent.
- **Prices in cents:** exact integer arithmetic in PHP and `DECIMAL` in MySQL avoid off-by-one-cent errors. The discount price gives the same result in PHP, with integer arithmetic, and in SQL, with `DECIMAL` arithmetic, so sorting matches what is shown.
- **A separate import page:** the product list stays focused on browsing, and the form can show errors next to its fields with the entered values kept. The CSRF token and the limits cover the main risk of an HTTP endpoint.
- **A streamed HTTP client with its own time limit:** streaming lets the 2 MiB limit stop the download, and an injected clock makes the time limit testable.
- **A small migration runner:** three SQL files do not need a migration library. The runner uses the foundation's Finder and PDO.
- **No new runtime dependencies:** the PHP code is built on the packages the foundation provides, and PHPUnit and PHP_CodeSniffer are the only Composer additions, both for development. The Tailwind CSS tool runs only when the stylesheet is built, and the font and the icons are files kept in the project.
- **Tailwind CSS through its standalone tool:** the official binary builds the stylesheet without adding Node.js and its packages to the project, and a checksum pins exactly what runs.
- **The stylesheet in a Docker volume:** building it on every start keeps it in step with the templates, and keeping it out of the project folder avoids committing generated files and root-owned files on Linux hosts.
- **A table on larger screens and cards on phones:** the table makes products easy to compare and sorts by its column headers; cards keep phones free of sideways scrolling. Both are rendered from the same components, and only one is shown at a time, so assistive technology meets each product once.
- **No JavaScript:** links and forms do everything the pages need, so they work in every browser and with every assistive technology, and there is no script to secure.
- **A self-hosted font:** it avoids sending visitors' addresses to a font service.
- **PSR-12 with a single exception:** the whole code base, tests included, follows PSR-12, so one tool and one rule set apply everywhere. Only the two entry scripts are exempt from the side-effects rule, because they have to define the application root before they run.
- **Actions and Composer pinned:** a tag can be moved to other code, but a commit SHA cannot, and a Composer download whose checksum does not match is refused. A CI run therefore always executes the reviewed tool code, and a new tool release cannot change a check's result without a change in the repository.

## Limitations
- There is no authentication or rate limiting beyond the import limits.
- Sessions are stored in the container's `/tmp`, so recreating the container, for example with `docker compose down` and `up`, invalidates an open import form; reloading the page fixes it.
- Prices from dummyjson.com are shown as euros without currency conversion.
- Images are shown from their dummyjson.com URLs and are not stored locally.
- Products removed from dummyjson.com are not removed locally.
- The 8-second limit per page is checked only when the response headers arrive and between reads, so a page can take one more 4-second wait. A server that sends its headers very slowly is limited only by the 4-second wait per network packet.
- A path that matches no route, a wrong method and an unexpected failure get the router's plain-text responses, not styled pages.
- There is no dark colour scheme.
- The stylesheet is rebuilt on every start, which adds a few seconds before the application is available.
