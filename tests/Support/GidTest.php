<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\Gid;
use PHPUnit\Framework\TestCase;

final class GidTest extends TestCase
{
    public function testOfBuildsGid(): void
    {
        self::assertSame('gid://shopify/Product/5', Gid::of('Product', 5));
        self::assertSame('gid://shopify/Customer/9', Gid::of('Customer', '9'));
    }

    public function testProductCoercesNumericId(): void
    {
        self::assertSame('gid://shopify/Product/7', Gid::product(7));
        self::assertSame('gid://shopify/Product/7', Gid::product('7'));
    }

    public function testProductPassesThroughAnExistingGid(): void
    {
        self::assertSame('gid://shopify/Product/7', Gid::product('gid://shopify/Product/7'));
    }

    public function testIdExtractsTrailingId(): void
    {
        self::assertSame('123', Gid::id('gid://shopify/Product/123'));
    }

    public function testIsGid(): void
    {
        self::assertTrue(Gid::isGid('gid://shopify/Product/1'));
        self::assertFalse(Gid::isGid('1'));
    }
}
