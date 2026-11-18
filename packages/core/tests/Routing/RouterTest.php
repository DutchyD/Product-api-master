<?php

declare(strict_types=1);

namespace MaxServ\Core\Tests\Routing;

use MaxServ\Core\Routing\Router;
use MaxServ\Core\Tests\Fake\FakeController;
use MaxServ\Core\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router as SymfonyRouter;

#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    #[Test]
    public function matching_route_calls_the_action_with_the_route_parameters_and_the_request(): void
    {
        $response = self::router(new RecordingLogger())->handle(Request::create('/items/42'));

        self::assertNotNull($response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('id=42 path=/items/42', $response->getContent());
    }

    #[Test]
    public function action_that_writes_its_own_output_gets_no_response(): void
    {
        $response = self::router(new RecordingLogger())->handle(Request::create('/written'));

        self::assertNull($response);
    }

    #[Test]
    public function path_without_a_route_gets_404(): void
    {
        $response = self::router(new RecordingLogger())->handle(Request::create('/unknown'));

        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    #[Test]
    public function route_called_with_a_method_it_does_not_allow_gets_405_with_the_allowed_methods(): void
    {
        $response = self::router(new RecordingLogger())->handle(Request::create('/items/42', 'POST'));

        self::assertNotNull($response);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
    }

    #[Test]
    public function failing_action_gets_500_without_details_and_the_failure_is_logged(): void
    {
        $logger = new RecordingLogger();

        $response = self::router($logger)->handle(Request::create('/failing'));

        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', $response->getContent());
        self::assertCount(1, $logger->errors());
        self::assertStringContainsString('GET /failing failed: RuntimeException: Database is down.', $logger->errors()[0]);
    }

    #[Test]
    public function controller_that_cannot_be_created_gets_500(): void
    {
        $logger = new RecordingLogger();

        $response = self::router($logger, withController: false)->handle(Request::create('/items/42'));

        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $logger->errors());
    }

    private static function router(RecordingLogger $logger, bool $withController = true): Router
    {
        $routes = new RouteCollection();
        $routes->add('item', new Route('/items/{id}', ['_controller' => FakeController::class . '::respond'], methods: ['GET']));
        $routes->add('written', new Route('/written', ['_controller' => FakeController::class . '::write']));
        $routes->add('failing', new Route('/failing', ['_controller' => FakeController::class . '::fail']));

        $container = new Container();
        if ($withController) {
            $container->set(FakeController::class, new FakeController());
        }

        return new Router($container, new SymfonyRouter(new ClosureLoader(), static fn (): RouteCollection => $routes), $logger);
    }
}
