<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Enum\MetafieldType;
use Baldie81\ShopifyGraphQL\Support\Metafield;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class ProductsMetafieldsTest extends MockClientTestCase
{
    public function testSetMetafieldsInjectsOwnerIdAndUsesMetafieldsSet(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldsSet' => ['metafields' => [['id' => 'gid://shopify/Metafield/1']], 'userErrors' => []]],
        ]))]);

        $result = $client->products()->setMetafields('55', [
            Metafield::make('specs', 'capacity', MetafieldType::NumberInteger, 25),
        ]);

        self::assertSame('gid://shopify/Metafield/1', $result['metafields'][0]['id']);
        self::assertSame([], $result['userErrors']);

        $body = $this->body();
        self::assertStringContainsString('MetafieldsSetInput!', $body['query']);
        $input = $body['variables']['metafields'][0];
        self::assertSame('gid://shopify/Product/55', $input['ownerId']);
        self::assertSame('specs', $input['namespace']);
        self::assertSame('capacity', $input['key']);
        self::assertSame('number_integer', $input['type']);
        self::assertSame('25', $input['value']);
    }

    public function testSetMetafieldsChunksAtTwentyFive(): void
    {
        $ok = (string) json_encode(['data' => ['metafieldsSet' => ['metafields' => [], 'userErrors' => []]]]);
        $client = $this->mockClient([new Response(200, [], $ok), new Response(200, [], $ok)]);

        $metafields = [];
        for ($i = 0; $i < 30; $i++) {
            $metafields[] = Metafield::integer('specs', "k{$i}", $i);
        }

        $client->products()->setMetafields('55', $metafields);

        self::assertCount(2, $this->history);
        self::assertCount(25, $this->body(0)['variables']['metafields']);
        self::assertCount(5, $this->body(1)['variables']['metafields']);
    }
}
