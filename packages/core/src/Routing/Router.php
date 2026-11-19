<?php

declare(strict_types=1);

namespace MaxServ\Core\Routing;

use MaxServ\Core\Logging\Logger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Router as SymfonyRouter;
use Throwable;

readonly class Router
{
    private const array PLAIN_TEXT = ['Content-Type' => 'text/plain; charset=UTF-8'];

    /**
     * Creates the router.
     *
     * @param Container $container The container that holds the controllers.
     * @param SymfonyRouter $router The router that holds the configured routes.
     * @param Logger $logger Records failed requests.
     */
    public function __construct(
        private Container $container,
        private SymfonyRouter $router,
        private Logger $logger,
    ) {
    }

    /**
     * Routes the current request, with a browser session attached, and sends the response.
     *
     * @return void
     * @throws \LogicException The PHP session extension is not loaded.
     */
    public function match(): void
    {
        $request = Request::createFromGlobals();
        $request->setSession($this->createSession($request));

        $this->handle($request)?->prepare($request)->send();
    }

    /**
     * Calls the controller action of the matching route with the route parameters and the request.
     *
     * A path without a route gets a 404 response, and a route called with a method it does not allow gets a 405
     * response. When the action fails, the failure is logged and a 500 response without details is returned.
     *
     * @param Request $request The request to route.
     *
     * @return Response|null The response to send, or null when the action wrote its output itself.
     */
    public function handle(Request $request): ?Response
    {
        try {
            $parameters = $this->router->matchRequest($request);
        } catch (ResourceNotFoundException) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND, self::PLAIN_TEXT);
        } catch (MethodNotAllowedException $exception) {
            return new Response('Method Not Allowed', Response::HTTP_METHOD_NOT_ALLOWED, [
                ...self::PLAIN_TEXT,
                'Allow' => implode(', ', $exception->getAllowedMethods()),
            ]);
        }

        try {
            [$controllerClass, $action] = explode('::', $parameters['_controller']);
            $result = $this->container->get($controllerClass)->$action($parameters, $request);
        } catch (Throwable $exception) {
            $this->logger->error(sprintf(
                'Request %s %s failed: %s: %s in %s:%d',
                $request->getMethod(),
                $request->getPathInfo(),
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));

            return new Response('Internal Server Error', Response::HTTP_INTERNAL_SERVER_ERROR, self::PLAIN_TEXT);
        }

        return $result instanceof Response ? $result : null;
    }

    private function createSession(Request $request): Session
    {
        return new Session(new NativeSessionStorage([
            'cookie_httponly' => true,
            'cookie_samesite' => 'lax',
            'cookie_secure' => $request->isSecure(),
            'use_strict_mode' => true,
        ]));
    }
}
