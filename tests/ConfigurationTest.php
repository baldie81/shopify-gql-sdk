<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests;

use Baldie81\ShopifyGraphQL\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    /**
     * @return iterable<string,array{string}>
     */
    public static function shops(): iterable
    {
        yield 'handle'         => ['my-store'];
        yield 'hostname'       => ['my-store.myshopify.com'];
        yield 'url'            => ['https://my-store.myshopify.com'];
        yield 'trailing slash' => ['https://my-store.myshopify.com/'];
        yield 'pasted admin'   => ['my-store.myshopify.com/admin'];
        yield 'url with query' => ['https://My-Store.myshopify.com/admin/products?x=1'];
    }

    #[DataProvider('shops')]
    public function testEndpointIsResolvedFromAnyShopForm(string $shop): void
    {
        self::assertSame(
            'https://my-store.myshopify.com/admin/api/2026-10/graphql.json',
            (new Configuration($shop, 'shpat_x'))->endpoint(),
        );
    }

    public function testCustomDomainIsKept(): void
    {
        self::assertSame(
            'https://shop.example.com/admin/api/2026-10/',
            (new Configuration('shop.example.com', 't', allowCustomHost: true))->baseUri(),
        );
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function foreignHosts(): iterable
    {
        yield 'custom domain'  => ['shop.example.com'];
        yield 'lookalike'      => ['my-store.myshopify.com.evil.example'];
        yield 'subdomain'      => ['evil.my-store.myshopify.com'];
        yield 'bare apex'      => ['myshopify.com'];
        yield 'loopback'       => ['127.0.0.1'];
        yield 'metadata'       => ['169.254.169.254'];
        yield 'pasted url'     => ['https://evil.example/admin'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignHosts')]
    public function testHostsOutsideMyshopifyAreRejectedUnlessAllowed(string $shop): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allowCustomHost');

        new Configuration($shop, 't');
    }

    public function testMyshopifyHostsNeedNoOptIn(): void
    {
        self::assertSame('https://my-store.myshopify.com/admin/api/2026-10/', (new Configuration('My-Store.myshopify.com', 't'))->baseUri());
        self::assertSame('https://my-store.myshopify.com/admin/api/2026-10/', (new Configuration('https://my-store.myshopify.com/admin', 't'))->baseUri());
    }

    public function testTheAccessTokenStaysOutOfDumpsAndSerialisation(): void
    {
        $config = new Configuration('my-store', 'shpat_secret');

        self::assertStringNotContainsString('shpat_secret', print_r($config, true));
        self::assertStringNotContainsString('shpat_secret', (string) json_encode($config));
        self::assertSame('shpat_secret', $config->accessToken); // still readable on purpose

        $this->expectException(\LogicException::class);
        serialize($config);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function badShops(): iterable
    {
        yield 'empty'      => [''];
        yield 'spaces'     => ['my store'];
        yield 'userinfo'   => ['user@evil.example'];
        yield 'underscore' => ['my_store'];
    }

    #[DataProvider('badShops')]
    public function testInvalidShopIsRejected(string $shop): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Configuration($shop, 't');
    }

    public function testInvalidApiVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Configuration('my-store', 't', apiVersion: '2026/10');
    }
}
