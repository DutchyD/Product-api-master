<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Fake;

use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers requests with queued responses or failures, and records every request.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue;

    /** @var list<RequestInterface> */
    private array $requests = [];

    /**
     * Creates the client.
     *
     * @param ResponseInterface|ClientExceptionInterface ...$answers The answers, in the order requests arrive.
     */
    public function __construct(ResponseInterface|ClientExceptionInterface ...$answers)
    {
        $this->queue = array_values($answers);
    }

    /**
     * Records the request and answers it with the next queued response or failure.
     *
     * @param RequestInterface $request The request.
     *
     * @return ResponseInterface The next queued response.
     * @throws ClientExceptionInterface The next queued answer is a failure.
     * @throws LogicException No answer is queued.
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $answer = array_shift($this->queue) ?? throw new LogicException('No answer queued for ' . $request->getUri());

        if ($answer instanceof ClientExceptionInterface) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * Returns the requests received so far.
     *
     * @return list<RequestInterface> The requests, in order.
     */
    public function requests(): array
    {
        return $this->requests;
    }
}
