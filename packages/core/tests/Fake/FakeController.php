<?php

declare(strict_types=1);

namespace MaxServ\Core\Tests\Fake;

use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A controller whose actions show what the router passes to them.
 */
final class FakeController
{
    /**
     * Returns a response that names the route parameter id and the request path.
     *
     * @param array<string, mixed> $parameters The route parameters.
     * @param Request $request The request.
     *
     * @return Response The response.
     */
    public function respond(array $parameters, Request $request): Response
    {
        return new Response("id={$parameters['id']} path={$request->getPathInfo()}", Response::HTTP_CREATED);
    }

    /**
     * Writes its output itself and returns nothing.
     *
     * @return void
     */
    public function write(): void
    {
    }

    /**
     * Always fails.
     *
     * @return never
     * @throws RuntimeException Always.
     */
    public function fail(): never
    {
        throw new RuntimeException('Database is down.');
    }
}
