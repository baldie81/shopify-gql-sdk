<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\GraphQL\Client;
use Baldie81\ShopifyGraphQL\Resource\BulkOperations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class BulkOperationsTest extends TestCase
{
    /** @var list<array{request:RequestInterface,response:mixed,error:mixed,options:array}> */
    private array $history = [];

    /**
     * @param list<mixed> $responses
     */
    private function resource(array $responses): BulkOperations
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $guzzle = new GuzzleClient([
            'handler'     => $stack,
            'base_uri'    => 'https://t.myshopify.com/admin/api/2025-07/',
            'http_errors' => true,
        ]);

        // No-op sleeper so poll() doesn't actually wait.
        return new BulkOperations(new Client($guzzle), static fn (int $us) => null);
    }

    public function testRunQueryReturnsBulkOperation(): void
    {
        $bulk = $this->resource([new Response(200, [], (string) json_encode([
            'data' => ['bulkOperationRunQuery' => [
                'bulkOperation' => ['id' => 'gid://shopify/BulkOperation/1', 'status' => 'CREATED'],
                'userErrors'    => [],
            ]],
        ]))]);

        $result = $bulk->runQuery('{ products { edges { node { id } } } }');

        self::assertSame('CREATED', $result['bulkOperation']['status']);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertStringContainsString('bulkOperationRunQuery', $body['query']);
        self::assertStringContainsString('products', $body['variables']['query']);
    }

    public function testGetFetchesByNodeId(): void
    {
        $bulk = $this->resource([new Response(200, [], (string) json_encode([
            'data' => ['node' => ['id' => 'gid://shopify/BulkOperation/1', 'status' => 'COMPLETED', 'url' => 'https://x/y.jsonl']],
        ]))]);

        $op = $bulk->get('gid://shopify/BulkOperation/1');

        self::assertSame('COMPLETED', $op['status']);
        self::assertSame('https://x/y.jsonl', $op['url']);
    }

    public function testPollLoopsUntilTerminalStatus(): void
    {
        $bulk = $this->resource([
            new Response(200, [], (string) json_encode(['data' => ['node' => ['id' => '1', 'status' => 'RUNNING']]])),
            new Response(200, [], (string) json_encode(['data' => ['node' => ['id' => '1', 'status' => 'COMPLETED', 'url' => 'https://x/y.jsonl']]])),
        ]);

        $op = $bulk->poll('gid://shopify/BulkOperation/1');

        self::assertSame('COMPLETED', $op['status']);
        self::assertCount(2, $this->history); // polled twice
    }

    public function testCancelReturnsPayload(): void
    {
        $bulk = $this->resource([new Response(200, [], (string) json_encode([
            'data' => ['bulkOperationCancel' => [
                'bulkOperation' => ['id' => '1', 'status' => 'CANCELING'],
                'userErrors'    => [],
            ]],
        ]))]);

        $result = $bulk->cancel('gid://shopify/BulkOperation/1');

        self::assertSame('CANCELING', $result['bulkOperation']['status']);
    }
}
