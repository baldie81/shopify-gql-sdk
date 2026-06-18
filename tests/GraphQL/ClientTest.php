<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\GraphQL;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Exception\GraphQLException;
use Baldie81\ShopifyGraphQL\Exception\ThrottledException;
use Baldie81\ShopifyGraphQL\Exception\TransportException;
use Baldie81\ShopifyGraphQL\GraphQL\Client;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /** @var list<int> */
    private array $slept = [];

    /**
     * @param list<mixed> $responses
     */
    private function client(array $responses, int $maxRetries = 2): Client
    {
        $guzzle = new GuzzleClient([
            'handler'     => HandlerStack::create(new MockHandler($responses)),
            'base_uri'    => 'https://t.myshopify.com/admin/api/2025-07/',
            'http_errors' => true,
        ]);

        return new Client($guzzle, 'graphql.json', $maxRetries, function (int $us): void {
            $this->slept[] = $us;
        });
    }

    private static function throttled(): Response
    {
        return new Response(200, [], (string) json_encode([
            'errors'     => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
            'extensions' => ['cost' => [
                'requestedQueryCost' => 100,
                'throttleStatus'     => ['maximumAvailable' => 1000, 'currentlyAvailable' => 50, 'restoreRate' => 50],
            ]],
        ]));
    }

    public function testExecuteReturnsData(): void
    {
        $client = $this->client([new Response(200, [], (string) json_encode(['data' => ['product' => ['id' => 'gid://shopify/Product/1']]]))]);

        self::assertSame(['product' => ['id' => 'gid://shopify/Product/1']], $client->execute('query{...}'));
    }

    public function testTopLevelErrorsThrowGraphQLException(): void
    {
        $client = $this->client([new Response(200, [], (string) json_encode([
            'errors' => [['message' => "Field 'bogus' doesn't exist"]],
        ]))]);

        try {
            $client->execute('query{...}');
            self::fail('expected GraphQLException');
        } catch (GraphQLException $e) {
            self::assertStringContainsString("Field 'bogus' doesn't exist", $e->getMessage());
            self::assertNotInstanceOf(ThrottledException::class, $e);
        }
    }

    public function testThrottledRetriesWithBackoffThenSucceeds(): void
    {
        $client = $this->client([
            self::throttled(),
            new Response(200, [], (string) json_encode(['data' => ['ok' => true]])),
        ]);

        self::assertSame(['ok' => true], $client->execute('query{...}'));
        // deficit 50 / restoreRate 50 = 1s -> 1_000_000 microseconds, slept exactly once.
        self::assertSame([1_000_000], $this->slept);
    }

    public function testThrottledExhaustsRetriesAndThrows(): void
    {
        $client = $this->client([self::throttled()], maxRetries: 0);

        try {
            $client->execute('query{...}');
            self::fail('expected ThrottledException');
        } catch (ThrottledException $e) {
            self::assertSame(50, $e->cost['throttleStatus']['currentlyAvailable']);
            self::assertSame([], $this->slept);
        }
    }

    public function testHttp429ThrowsThrottledException(): void
    {
        $client = $this->client([new Response(429, [], 'Too Many Requests')]);

        $this->expectException(ThrottledException::class);
        $client->execute('query{...}');
    }

    public function testHttp500ThrowsGraphQLExceptionWithStatus(): void
    {
        $client = $this->client([new Response(500, [], 'boom')]);

        try {
            $client->execute('query{...}');
            self::fail('expected GraphQLException');
        } catch (GraphQLException $e) {
            self::assertSame(500, $e->statusCode);
        }
    }

    public function testConnectFailureThrowsTransportException(): void
    {
        $client = $this->client([new ConnectException('cannot connect', new Request('POST', 'graphql.json'))]);

        $this->expectException(TransportException::class);
        $client->execute('query{...}');
    }
}
