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
use Baldie81\ShopifyGraphQL\Resource\Files;
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

    public function testImportMutationStagesJsonlThenRunsMutation(): void
    {
        $gqlHistory = [];
        $upHistory  = [];

        $gqlStack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['data' => ['stagedUploadsCreate' => [
                'stagedTargets' => [[
                    'url'         => 'https://gcs/bulk',
                    'resourceUrl' => 'https://gcs/bulk/res',
                    'parameters'  => [['name' => 'key', 'value' => 'tmp/bulk/vars.jsonl']],
                ]],
                'userErrors' => [],
            ]]])),
            new Response(200, [], (string) json_encode(['data' => ['bulkOperationRunMutation' => [
                'bulkOperation' => ['id' => 'gid://shopify/BulkOperation/9', 'status' => 'CREATED'],
                'userErrors'    => [],
            ]]])),
        ]));
        $gqlStack->push(Middleware::history($gqlHistory));
        $gql = new Client(new GuzzleClient([
            'handler'     => $gqlStack,
            'base_uri'    => 'https://t.myshopify.com/admin/api/2025-07/',
            'http_errors' => true,
        ]));

        $upStack = HandlerStack::create(new MockHandler([new Response(201)]));
        $upStack->push(Middleware::history($upHistory));
        $files = new Files($gql, new GuzzleClient(['handler' => $upStack, 'http_errors' => true]));

        $bulk = new BulkOperations($gql, static fn (int $us) => null, $files);

        $result = $bulk->importMutation(
            'mutation call($input: ProductInput!) { productUpdate(input: $input) { product { id } } }',
            [
                ['input' => ['id' => 'gid://shopify/Product/1', 'title' => 'A']],
                ['input' => ['id' => 'gid://shopify/Product/2', 'title' => 'B']],
            ],
        );

        self::assertSame('CREATED', $result['bulkOperation']['status']);

        $stagedVars = json_decode((string) $gqlHistory[0]['request']->getBody(), true)['variables'];
        self::assertSame('BULK_MUTATION_VARIABLES', $stagedVars['input'][0]['resource']);

        // bulkOperationRunMutation must receive the staged "key" as stagedUploadPath.
        $runVars = json_decode((string) $gqlHistory[1]['request']->getBody(), true)['variables'];
        self::assertSame('tmp/bulk/vars.jsonl', $runVars['path']);

        self::assertCount(1, $upHistory); // single JSONL push
    }

    public function testResultsStreamsDecodedJsonlRows(): void
    {
        // gql isn't used by results(); a downloader returns the JSONL file.
        $gql = new Client(new GuzzleClient([
            'handler'  => HandlerStack::create(new MockHandler([])),
            'base_uri' => 'https://t.myshopify.com/admin/api/2025-07/',
        ]));

        $jsonl = json_encode(['data' => ['productUpdate' => ['product' => ['id' => 'gid://shopify/Product/1'], 'userErrors' => []]]]) . "\n"
            . json_encode(['data' => ['productUpdate' => ['product' => ['id' => 'gid://shopify/Product/2'], 'userErrors' => [['message' => 'bad']]]]]) . "\n";

        $downloader = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $jsonl)]))]);
        $bulk       = new BulkOperations($gql, static fn (int $us) => null, null, $downloader);

        $rows = iterator_to_array($bulk->results(['url' => 'https://gcs.example/result.jsonl']));

        self::assertCount(2, $rows);
        self::assertSame('gid://shopify/Product/1', $rows[0]['data']['productUpdate']['product']['id']);
        self::assertSame('bad', $rows[1]['data']['productUpdate']['userErrors'][0]['message']);
    }

    public function testResultsYieldsNothingWithoutUrl(): void
    {
        $gql  = new Client(new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([])), 'base_uri' => 'https://t.myshopify.com/admin/api/2025-07/']));
        $bulk = new BulkOperations($gql);

        self::assertSame([], iterator_to_array($bulk->results(['status' => 'RUNNING'])));
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
