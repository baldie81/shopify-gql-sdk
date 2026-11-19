<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Baldie81\ShopifyGraphQL\Configuration;
use Baldie81\ShopifyGraphQL\ShopifyClient;
use PHPUnit\Framework\TestCase;

final class ShopifyClientTest extends TestCase
{
    private static function guzzle(string $baseUri): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([])), 'base_uri' => $baseUri]);
    }

    public function testFromGuzzleReadsTheApiVersionFromTheBaseUri(): void
    {
        $client = ShopifyClient::fromGuzzle(self::guzzle('https://t.myshopify.com/admin/api/2025-07/'));

        // The version gates behaviour (e.g. @idempotent), so it must match where requests actually go.
        self::assertSame('2025-07', $client->graphql()->apiVersion());
    }

    public function testFromGuzzleRejectsAVersionThatContradictsTheBaseUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ShopifyClient::fromGuzzle(self::guzzle('https://t.myshopify.com/admin/api/2025-07/'), '2026-10');
    }

    public function testFromGuzzleFallsBackToTheExplicitVersion(): void
    {
        $client = ShopifyClient::fromGuzzle(self::guzzle('https://proxy.internal/shopify/'), '2026-04');

        self::assertSame('2026-04', $client->graphql()->apiVersion());
    }

    public function testCreateWiresTheConfiguredVersionAndSharesCollaborators(): void
    {
        $client = ShopifyClient::create(new Configuration('my-store', 'shpat_x', apiVersion: '2026-04'));

        self::assertSame('2026-04', $client->graphql()->apiVersion());
        self::assertSame($client->files(), $client->files());
    }


    public function testCreateDoesNotFollowRedirects(): void
    {
        // A redirect to another host would carry the access-token header along.
        $client = ShopifyClient::create(new Configuration('my-store', 'shpat_x'));
        $guzzle = (new \ReflectionProperty($client->graphql(), 'client'))->getValue($client->graphql());

        self::assertInstanceOf(Client::class, $guzzle);
        self::assertFalse($guzzle->getConfig('allow_redirects'));
    }

    public function testDumpingTheClientDoesNotShowTheAccessToken(): void
    {
        $client = ShopifyClient::create(new Configuration('my-store', 'shpat_secret'));

        self::assertStringNotContainsString('shpat_secret', print_r($client, true));
        self::assertStringNotContainsString('shpat_secret', print_r($client->graphql(), true));
    }
}
