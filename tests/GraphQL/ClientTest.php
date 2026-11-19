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

    public function testApiVersionAtLeast(): void
    {
        $at = fn (string $v): \Baldie81\ShopifyGraphQL\GraphQL\Client => new Client(
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([])), 'base_uri' => 'https://t.myshopify.com/admin/api/x/']),
            'graphql.json', 2, null, $v,
        );

        self::assertTrue($at('2026-04')->apiVersionAtLeast('2026-04'));
        self::assertTrue($at('2026-07')->apiVersionAtLeast('2026-04'));
        self::assertTrue($at('latest')->apiVersionAtLeast('2026-04'));
        self::assertFalse($at('2025-07')->apiVersionAtLeast('2026-04'));
        self::assertFalse($at('2026-01')->apiVersionAtLeast('2026-04'));
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

    public function testDataReturnedAlongsideErrorsIsKeptOnTheException(): void
    {
        // What Shopify sends for a field the app isn't approved to read.
        $client = $this->client([new Response(200, [], (string) json_encode([
            'data'   => ['customer' => ['id' => 'gid://shopify/Customer/1', 'defaultPhoneNumber' => null]],
            'errors' => [['message' => 'This app is not approved to use the phoneNumber field.', 'path' => ['customer', 'defaultPhoneNumber', 'phoneNumber']]],
        ]))]);

        try {
            $client->execute('query{...}');
            self::fail('expected GraphQLException');
        } catch (GraphQLException $e) {
            self::assertSame('gid://shopify/Customer/1', $e->data['customer']['id'] ?? null);
            self::assertCount(1, $e->errors);
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

    public function testHttp429ThrowsThrottledExceptionOnceRetriesAreSpent(): void
    {
        $client = $this->client([new Response(429, [], 'Too Many Requests')], maxRetries: 0);

        $this->expectException(ThrottledException::class);
        $client->execute('query{...}');
    }

    public function testHttp429IsRetriedHonouringRetryAfter(): void
    {
        $client = $this->client([
            new Response(429, ['Retry-After' => '2'], ''),
            new Response(200, [], (string) json_encode(['data' => ['ok' => true]])),
        ]);

        self::assertSame(['ok' => true], $client->execute('mutation { x }')); // a 429 was never applied: safe for mutations too
        self::assertSame([2_000_000], $this->slept);
    }

    public function testGatewayErrorIsRetriedForQueries(): void
    {
        $client = $this->client([
            new Response(503, [], ''),
            new Response(200, [], (string) json_encode(['data' => ['ok' => true]])),
        ]);

        self::assertSame(['ok' => true], $client->execute('query { x }'));
        self::assertSame([1_000_000], $this->slept);
    }

    public function testGatewayErrorIsRetriedForShorthandAndCommentedQueries(): void
    {
        foreach (['{ x }', "# list\n, query Q { x }"] as $document) {
            $this->slept = [];
            $client      = $this->client([
                new Response(503, [], ''),
                new Response(200, [], (string) json_encode(['data' => ['ok' => true]])),
            ]);

            self::assertSame(['ok' => true], $client->execute($document));
            self::assertSame([1_000_000], $this->slept);
        }
    }

    public function testGatewayErrorIsNotRetriedUnlessTheDocumentIsPlainlyAQuery(): void
    {
        $documents = [
            "# create a product\nmutation { productCreate { product { id } } }", // comment before the mutation
            "\u{FEFF}  mutation { x }",
            'fragment F on Product { id } mutation { x { ...F } }',
            'queryish { x }',
        ];

        foreach ($documents as $document) {
            $this->slept = [];
            $client      = $this->client([new Response(503, [], '')]);

            try {
                $client->execute($document);
                self::fail('expected GraphQLException');
            } catch (GraphQLException $e) {
                self::assertSame(503, $e->statusCode);
                self::assertSame([], $this->slept, $document);
            }
        }
    }

    public function testGatewayErrorIsNotRetriedForMutations(): void
    {
        // The write may already have been applied, so a blind retry could duplicate it.
        $client = $this->client([new Response(503, [], '')]);

        try {
            $client->execute('mutation { productCreate { product { id } } }');
            self::fail('expected GraphQLException');
        } catch (GraphQLException $e) {
            self::assertSame(503, $e->statusCode);
            self::assertSame([], $this->slept);
        }
    }

    public function testStringErrorsOnA200AreThrownNotSwallowed(): void
    {
        $client = $this->client([new Response(200, [], '{"errors":"Not Found"}')]);

        try {
            $client->execute('query{...}');
            self::fail('expected GraphQLException');
        } catch (GraphQLException $e) {
            self::assertSame('Not Found', $e->getMessage());
            self::assertSame([['message' => 'Not Found']], $e->errors);
        }
    }

    public function testAuthFailureThrowsEvenWhenTheClientDisablesHttpErrors(): void
    {
        $guzzle = new GuzzleClient([
            'handler'     => HandlerStack::create(new MockHandler([
                new Response(401, [], '{"errors":"[API] Invalid API key or access token"}'),
            ])),
            'base_uri'    => 'https://t.myshopify.com/admin/api/2026-10/',
            'http_errors' => false,
        ]);

        try {
            (new Client($guzzle))->execute('query{...}');
            self::fail('expected GraphQLException');
        } catch (GraphQLException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertStringContainsString('Invalid API key', $e->getMessage());
        }
    }

    public function testThrottledExceptionKeepsTheErrors(): void
    {
        $client = $this->client([self::throttled()], maxRetries: 0);

        try {
            $client->execute('query{...}');
            self::fail('expected ThrottledException');
        } catch (ThrottledException $e) {
            self::assertSame('THROTTLED', $e->errors[0]['extensions']['code']);
        }
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
        $client = $this->client([new ConnectException('cannot connect', new Request('POST', 'graphql.json'))], maxRetries: 0);

        $this->expectException(TransportException::class);
        $client->execute('query { x }');
    }

    public function testConnectFailureIsRetriedForQueries(): void
    {
        $failure = new ConnectException('cannot connect', new Request('POST', 'graphql.json'));
        $client  = $this->client([$failure, $failure, new Response(200, [], (string) json_encode(['data' => ['ok' => true]]))]);

        self::assertSame(['ok' => true], $client->execute('query { x }'));
        self::assertSame([1_000_000, 2_000_000], $this->slept);
    }

    public function testConnectFailureIsNotRetriedForMutations(): void
    {
        // A timeout doesn't say whether the write went through.
        $client = $this->client([new ConnectException('timed out', new Request('POST', 'graphql.json'))]);

        try {
            $client->execute('mutation { x }');
            self::fail('expected TransportException');
        } catch (TransportException) {
            self::assertSame([], $this->slept);
        }
    }
}
