<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Baldie81\ShopifyGraphQL\ShopifyClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Base case that wires a {@see ShopifyClient} onto a Guzzle MockHandler and
 * records outgoing requests for assertions.
 */
abstract class MockClientTestCase extends TestCase
{
    /** @var list<array{request:RequestInterface,response:mixed,error:mixed,options:array}> */
    protected array $history = [];

    /**
     * @param list<mixed> $responses queued Response/Exception objects
     */
    protected function mockClient(array $responses): ShopifyClient
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $guzzle = new Client([
            'handler'     => $stack,
            'base_uri'    => 'https://test.myshopify.com/admin/api/2025-07/',
            'http_errors' => true,
            'headers'     => ['X-Shopify-Access-Token' => 'shptest'],
        ]);

        return ShopifyClient::fromGuzzle($guzzle);
    }

    protected function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    /**
     * Decode the GraphQL request body of the last (or nth) request.
     *
     * @return array{query:string,variables:array<string,mixed>}
     */
    protected function body(int $index = -1): array
    {
        $entry   = $index < 0 ? $this->history[array_key_last($this->history)] : $this->history[$index];
        $decoded = json_decode((string) $entry['request']->getBody(), true);

        return ['query' => $decoded['query'] ?? '', 'variables' => $decoded['variables'] ?? []];
    }
}
