<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class ProductsSetDeleteTest extends MockClientTestCase
{
    public function testSetSendsProductSetInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productSet' => [
                'product' => ['id' => 'gid://shopify/Product/55', 'variants' => ['nodes' => [['id' => 'v1', 'sku' => 'A-RED-S']]]],
                'userErrors' => [],
            ]],
        ]))]);

        $result = $client->products()->set([
            'title'          => 'Tee',
            'productOptions' => [['name' => 'Color', 'values' => [['name' => 'Red']]]],
            'variants'       => [['optionValues' => [['optionName' => 'Color', 'name' => 'Red']], 'price' => '19.90']],
        ]);

        self::assertSame('gid://shopify/Product/55', $result['product']['id']);
        $body = $this->body();
        self::assertStringContainsString('ProductSetInput!', $body['query']);
        self::assertSame('Color', $body['variables']['input']['productOptions'][0]['name']);
    }

    public function testDeleteReturnsDeletedId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productDelete' => ['deletedProductId' => 'gid://shopify/Product/55', 'userErrors' => []]],
        ]))]);

        $result = $client->products()->delete('55');

        self::assertSame('gid://shopify/Product/55', $result['deletedProductId']);
        self::assertSame('gid://shopify/Product/55', $this->body()['variables']['input']['id']);
    }
}
