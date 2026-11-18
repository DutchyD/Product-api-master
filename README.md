# MaxServ B.V. Assignment

This project acts as a foundational implementation for the MaxServ B.V. assignment. You are welcome to modify, expand, and refactor the code as needed to meet the assignment criteria. Please note that while PHP packages are allowed, the use of PHP frameworks is not permitted.

## Getting Started
To execute the project, you will need to install the following prerequisites:

- `Docker` - A platform for developing, shipping, and running applications. [Learn more](https://www.docker.com/get-started).
- `Composer` - A dependency manager for PHP. [Learn more](https://getcomposer.org/).
- `Git` - A distributed version control system. (Only required if your code is managed in a Git repository) [Learn more](https://git-scm.com/).

Now that all prerequisites are installed, we can initiate the project by following the steps below:

1. Execute the `composer install` command to install the project dependencies. The project requires PHP 8.5.
2. Execute the `docker compose up` command to launch the application. Add `--build` after changing the `Dockerfile`, and `-d` to run it in the background. The `app` container runs PHP 8.5 with Apache, which sends every path that is not a file in `public` to `public/index.php`.

On every start, the `migrate` service waits until MySQL accepts connections, creates or updates the database tables with `bin/migrate`, and stops. The `app` service starts only after the migrations succeeded. When they fail, `docker compose logs migrate` shows why.

The project should now be operational and accessible at `http://localhost:8080`.
You can also access phpMyAdmin at `http://localhost:8081`.

### Application flow
The application begins from a single entry point, `public/index.php`, which ensures composer autoloading and initiates a bootstrap process.
The bootstrap process sets up the application. It creates a `Container` to facilitate dependency injection and a `Router` to manage incoming requests.

Routes are specified in `config/routes.yaml`. The `/` route links to the `IndexController` and the `index` method, which renders the `index.html.twig` template from the `templates` directory with a link to the product list.

The `Router` calls the matched controller action with the route parameters and the current `Request`. The request carries a session, which starts only when an action uses it. When an action returns a `Response`, the `Router` sends it. A path that matches no route gets a `404 Not Found` response, and a route called with a method it does not allow gets a `405 Method Not Allowed` response. When an action fails, for example because the database cannot be reached, the error is written to the PHP error log and the response is a plain `500 Internal Server Error` without details. The container logs PHP errors and does not show them in responses.

## Products
The application stores products from [dummyjson.com](https://dummyjson.com/products) in MySQL and shows them in a paginated list.

### Product model
A product keeps the dummyjson.com id as its own id, and stores its title, description, category, price, discount percentage, rating, stock, brand (optional), SKU, weight, dimensions (width, height and depth), warranty, shipping and availability information (`In Stock`, `Low Stock` or `Out of Stock`), return policy, minimum order quantity, barcode, QR code URL, thumbnail URL, the creation and update dates from dummyjson.com, its tags and its image URLs. Tags and images are stored in their own tables and keep their order. Reviews are not stored.

The discount price is the price reduced by the discount percentage, rounded to whole cents with halves rounded up.

### Product list
`GET /products` shows the stored products in a table with the thumbnail, title, price, brand and category. The title links to the product's detail page. The price column shows the normal price, and when the product has a discount, the normal price is struck through next to the discount price. Prices are shown in euros in Dutch notation, for example `€ 1.234,56`. A product without a brand shows a dash.

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

- **Filtering:** a filter form above the table has a dropdown with every stored category and one with every stored brand, in alphabetical order. A filter shows only the products whose category or brand equals the chosen value exactly, including case and accents; with both filters set, a product must match both. A link clears the filters.
- **Sorting:** the Title, Price, Brand and Category column headers are links. Clicking a header sorts the list by that column in ascending order, and clicking the header of the sorted column again reverses the order. The sorted column shows an arrow for its direction. Price sorts by the price the customer pays: the discount price, or the normal price without a discount. Products without a brand come last in both directions. Products with the same value keep the order of their id.
- **Pagination:** a page after the last page shows the last page. The page shows the current page, the total number of pages and matching products, links to the previous and next page, and links to change the number of products per page.

Every link and the filter form keep the current filters, sorting and number of products per page. Changing a filter, the sorting or the number per page starts again at page 1. Without stored products, the page says so and shows only the import form. When stored products exist but none match the filters, the page says so.

### Product detail
`GET /products/{id}` shows every stored field of one product, including its images, tags and dimensions. An unknown id gets a `404 Not Found` page.

### Importing products
The product list has an import form. It posts to `POST /products/import` with:

| Field | Allowed values | Meaning |
| --- | --- | --- |
| `count` | 1 to 200 | How many products to import |
| `offset` | 0 to 10000 | How many dummyjson.com products to skip first |

The import fetches the products from dummyjson.com in pages of at most 100 and stores them, so one import of 200 can store every dummyjson.com product. A product that already exists is updated, including its tags and images, so importing the same products again does not create duplicates. A product with missing or invalid data, or an entry that is not a product object, is skipped and counted. After the import, the browser is redirected to the product list, which shows how many products were imported and how many were skipped. When the offset is past the last dummyjson.com product, nothing is imported and the message says so.

The form carries a token that is bound to the browser session. A request without a valid token gets a `403 Forbidden` page and imports nothing. An invalid `count` or `offset` imports nothing and shows the allowed values. When dummyjson.com cannot be reached, returns an invalid response, or does not deliver a page within 8 seconds, the list shows that the import failed. Products stored by earlier pages of the same import are kept.

### Database migrations
The database tables are created by the SQL files in `database/migrations`. The `bin/migrate` command applies, in file name order, every file that has not been applied yet, and records each applied file in the `schema_migrations` table. Each file holds one SQL statement that is safe to run again, such as `CREATE TABLE IF NOT EXISTS`, because MySQL cannot undo a table change when recording it fails. Running the command again applies nothing new.

## Testing
Execute the `vendor/bin/phpunit` command to run the unit tests. The tests use no network and no database. A test that triggers a deprecation fails.

## Project Structure

The project is structured as follows:

### Git
This project employs Git for version control, and basic Git configuration is included.

- `.gitignore` - Lists files and directories that should be ignored by Git. [Learn more](https://git-scm.com/docs/gitignore).

### Docker
The project can be run within a Docker container, with the necessary Docker configuration provided.

- `Dockerfile` - Contains instructions for building the Docker image. [Learn more](https://docs.docker.com/engine/reference/builder/).
- `docker-compose.yml` - Defines the services, networks, and volumes for running the Docker container. [Learn more](https://docs.docker.com/compose/compose-file/).
- `.dockerignore` - Lists files and directories to be ignored by Docker. [Learn more](https://docs.docker.com/engine/reference/builder/#dockerignore-file).

### Composer
This project utilizes Composer for dependency management, with the relevant configuration included.

- `composer.json` - The file used to manage and install project dependencies. [Learn more](https://getcomposer.org/doc/04-schema.md).
- `composer.lock` - The exact versions of the installed dependencies.

### Configuration
The project includes configuration files for various tools and services.

- `config/routes.yaml` - Contains the routing configuration for the project. [Learn more](https://symfony.com/doc/current/routing.html#creating-routes-in-yaml-xml-or-php-files).
- `phpunit.xml.dist` - Contains the PHPUnit configuration. [Learn more](https://docs.phpunit.de/).

### Command line
The `bin` directory contains the command line scripts. They run inside the `app` container, which provides the database settings.

- `bin/migrate` - Applies the database migrations.

### Database
The `database/migrations` directory contains the SQL migrations that create the database tables.

### Packages
The `packages` directory contains the code for the project.

- `packages/core` - Contains the core functionality of the application, with its unit tests in `packages/core/tests`.
- `packages/app` - The package where the assignment requirements are implemented: the product model, the dummyjson.com import, the product pages and their unit tests in `packages/app/tests`.

### Public
The `public` directory contains the entry point for the application. It is advisable to keep this directory as clean as possible and not alter the logic in the `index.php` file.

### Templates
The `templates` directory contains the Twig templates for the application.

- `templates/index.html.twig` - The start page, with a link to the product list.
- `templates/layout.html.twig` - The page layout of the product pages.
- `templates/products` - The product list and product detail pages.

## Core Package
The `core` package encompasses the core functionality of the application. The `core` offers some default functionality that can be utilized while constructing your implementation.

### Database Connection
The `core` package provides a database connection using PDO for MySQL. The database connection is configured through environment variables, which can be set in the `docker-compose.yml` file. The connection uses the `utf8mb4` character set, real prepared statements, and does not allow several SQL statements in one call.

### Database Migrations
The `core` package provides the migration runner that `bin/migrate` uses.

### Logging
The `core` package provides a `Logger` interface for errors. Its default implementation writes to the PHP error log.

### Template Rendering
The `core` package includes a `TemplateRenderer` class that can be used to render Twig templates. The `TemplateRenderer` class is initialized with the `Twig` environment and can be employed to render templates. All templates should be defined in the `templates` directory.

## Provided PHP packages
With the foundation, we provide you with several PHP packages out-of-the-box. These packages are included in the `composer.json` file and can be utilized to enhance the functionality of the project.

- `guzzlehttp/guzzle` - A PHP HTTP client that simplifies sending HTTP requests. [Learn more](https://docs.guzzlephp.org/en/stable/).
- `symfony/routing` - Offers tools for routing requests to PHP code. [Learn more](https://symfony.com/doc/current/routing.html).
- `symfony/config` - Assists in finding, loading, combining, autofilling, and validating configuration values of any kind, regardless of their source (YAML, XML, INI files, or even a database). [Learn more](https://symfony.com/doc/current/components/config.html).
- `symfony/yaml` - Provides tools for handling YAML files. [Learn more](https://symfony.com/doc/current/components/yaml.html).
- `symfony/http-foundation` - Defines an object-oriented layer for the HTTP specification. [Learn more](https://symfony.com/doc/current/components/http_foundation.html).
- `symfony/dependency-injection` - Enables you to standardize and centralize the way objects are constructed in your application. [Learn more](https://symfony.com/doc/current/components/dependency_injection.html).
- `symfony/finder` - Offers tools for locating files and directories. [Learn more](https://symfony.com/doc/current/components/finder.html).
- `ext-pdo` - The PHP Data Objects (PDO) extension provides a lightweight, consistent interface for accessing databases in PHP. [Learn more](https://www.php.net/manual/en/book.pdo.php).
- `twig/twig` - A flexible, fast, and secure template engine for PHP. [Learn more](https://twig.symfony.com/).

For development, `phpunit/phpunit` runs the unit tests. [Learn more](https://phpunit.de/).
