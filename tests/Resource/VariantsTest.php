<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\VariantInput;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class VariantsTest extends MockClientTestCase
{
    public function testListReturnsVariantNodes(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['product' => ['variants' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'A', 'price' => '19.90', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/1', 'tracked' => true]],
            ]]]],
        ]))]);

        $variants = $client->variants()->list('55');

        self::assertSame('gid://shopify/InventoryItem/1', $variants[0]['inventoryItem']['id']);
        self::assertSame('gid://shopify/Product/55', $this->body()['variables']['id']);
    }

    public function testUpdateSendsBulkInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productVariantsBulkUpdate' => ['productVariants' => [['id' => 'gid://shopify/ProductVariant/1', 'price' => '17.50']], 'userErrors' => []]],
        ]))]);

        $client->variants()->update('55', [VariantInput::make('gid://shopify/ProductVariant/1')->price(17.5)->compareAtPrice(24)]);

        $body = $this->body();
        self::assertStringContainsString('productVariantsBulkUpdate', $body['query']);
        self::assertSame('gid://shopify/Product/55', $body['variables']['productId']);
        self::assertSame('17.50', $body['variables']['variants'][0]['price']);
        self::assertSame('24.00', $body['variables']['variants'][0]['compareAtPrice']);
    }

    public function testCreateSendsBulkCreate(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productVariantsBulkCreate' => ['productVariants' => [['id' => 'gid://shopify/ProductVariant/9']], 'userErrors' => []]],
        ]))]);

        $client->variants()->create('55', [VariantInput::make('x')->price(9.99)]);

        self::assertStringContainsString('productVariantsBulkCreate', $this->body()['query']);
    }

    public function testDeleteSendsVariantsIds(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productVariantsBulkDelete' => ['product' => ['id' => 'gid://shopify/Product/55'], 'userErrors' => []]],
        ]))]);

        $client->variants()->delete('55', ['gid://shopify/ProductVariant/1', 'gid://shopify/ProductVariant/2']);

        $body = $this->body();
        self::assertStringContainsString('productVariantsBulkDelete', $body['query']);
        self::assertSame(['gid://shopify/ProductVariant/1', 'gid://shopify/ProductVariant/2'], $body['variables']['variantsIds']);
    }
}
