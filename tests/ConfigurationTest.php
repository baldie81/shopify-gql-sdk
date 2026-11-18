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
        self::assertSame('https://shop.example.com/admin/api/2026-10/', (new Configuration('shop.example.com', 't'))->baseUri());
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
