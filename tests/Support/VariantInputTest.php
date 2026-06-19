<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\Arrayable;
use Baldie81\ShopifyGraphQL\Support\VariantInput;
use PHPUnit\Framework\TestCase;

final class VariantInputTest extends TestCase
{
    public function testBuildsVariantAndInventoryItemSubtree(): void
    {
        $input = VariantInput::make('gid://shopify/ProductVariant/1')
            ->price(19.9)
            ->compareAtPrice(24)
            ->sku('TEE-RED-S')
            ->tracked();

        self::assertInstanceOf(Arrayable::class, $input);
        self::assertSame([
            'id'             => 'gid://shopify/ProductVariant/1',
            'price'          => '19.90',          // floats formatted to money strings
            'compareAtPrice' => '24.00',
            'inventoryItem'  => ['sku' => 'TEE-RED-S', 'tracked' => true],
        ], $input->toArray());
    }

    public function testStringPricePassesThrough(): void
    {
        self::assertSame('15.99', VariantInput::make('v')->price('15.99')->toArray()['price']);
    }
}
