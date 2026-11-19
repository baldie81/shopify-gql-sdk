<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use Baldie81\ShopifyGraphQL\Exception\TransportException;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Enum\BulkOperationType;
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

    public function testCurrentAcceptsEnumType(): void
    {
        $bulk = $this->resource([new Response(200, [], (string) json_encode([
            'data' => ['bulkOperations' => ['nodes' => [['id' => 'gid://shopify/BulkOperation/9', 'status' => 'COMPLETED']], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c']]],
        ]))]);

        $op = $bulk->current(BulkOperationType::Mutation);

        self::assertSame('gid://shopify/BulkOperation/9', $op['id']);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        // newest operation of that type, via the non-deprecated connection
        self::assertStringContainsString('bulkOperations(', $body['query']);
        self::assertStringNotContainsString('currentBulkOperation', $body['query']);
        self::assertSame('operation_type:mutation', $body['variables']['query']);
        self::assertSame(1, $body['variables']['first']);
        self::assertCount(1, $this->history); // limit 1: no second page fetched
    }

    public function testCurrentStillAcceptsStringType(): void
    {
        $bulk = $this->resource([new Response(200, [], (string) json_encode(['data' => ['bulkOperations' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]))]);

        self::assertNull($bulk->current('QUERY')); // backward-compatible

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertSame('operation_type:query', $body['variables']['query']);
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

    public function testPollThrowsWhenStillRunningAfterTheBudget(): void
    {
        $running = (string) json_encode(['data' => ['node' => ['id' => '1', 'status' => 'RUNNING']]]);
        $bulk    = $this->resource([new Response(200, [], $running), new Response(200, [], $running), new Response(200, [], $running)]);

        try {
            $bulk->poll('gid://shopify/BulkOperation/1', maxAttempts: 2);
            self::fail('expected PollTimeoutException');
        } catch (\Baldie81\ShopifyGraphQL\Exception\PollTimeoutException $e) {
            self::assertSame('RUNNING', $e->operation['status']); // last-seen state, to resume or cancel
            self::assertCount(3, $this->history);
        }
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

    private function downloading(Response $response): BulkOperations
    {
        $gql = new Client(new GuzzleClient([
            'handler'  => HandlerStack::create(new MockHandler([])),
            'base_uri' => 'https://t.myshopify.com/admin/api/2026-10/',
        ]));
        $downloader = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([$response])), 'http_errors' => false]);

        return new BulkOperations($gql, static fn (int $us) => null, null, $downloader);
    }

    public function testResultsThrowsWhenTheDownloadIsRefusedWithoutAnException(): void
    {
        // An injected downloader with http_errors off doesn't throw on a 403 —
        // and its XML error body must not be parsed as rows.
        $bulk = $this->downloading(new Response(403, [], '<Error>expired</Error>'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('HTTP 403');
        iterator_to_array($bulk->results(['url' => 'https://gcs.example/result.jsonl']));
    }

    public function testResultsWrapsADownloadThatBreaksPartWay(): void
    {
        $reads  = 0;
        $stream = FnStream::decorate(Utils::streamFor(''), [
            'eof'  => static fn (): bool => false,
            'read' => static function () use (&$reads): string {
                if ($reads++ === 0) {
                    return '{"id":1}' . "\n" . '{"id":';
                }

                throw new \RuntimeException('connection reset');
            },
        ]);
        $bulk = $this->downloading(new Response(200, [], $stream));

        $rows = [];
        try {
            foreach ($bulk->results(['url' => 'https://gcs.example/result.jsonl']) as $row) {
                $rows[] = $row;
            }
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertSame([['id' => 1]], $rows); // the complete row was delivered before the break
            self::assertStringContainsString('connection reset', $e->getMessage());
        }
    }
}
