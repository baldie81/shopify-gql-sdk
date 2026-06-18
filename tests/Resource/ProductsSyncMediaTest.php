<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\MediaSlot;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class ProductsSyncMediaTest extends MockClientTestCase
{
    public function testFindIdBySku(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productVariants' => ['nodes' => [['product' => ['id' => 'gid://shopify/Product/55']]]]],
        ]))]);

        self::assertSame('gid://shopify/Product/55', $client->products()->findIdBySku('ABC-1'));
        self::assertSame('sku:ABC-1', $this->body()['variables']['q']);
    }

    public function testFindIdBySkuReturnsNullWhenNoMatch(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productVariants' => ['nodes' => []]],
        ]))]);

        self::assertNull($client->products()->findIdBySku('NOPE'));
    }

    public function testReorderMediaSendsZeroBasedStringPositions(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productReorderMedia' => ['job' => ['id' => 'gid://shopify/Job/1', 'done' => false], 'mediaUserErrors' => []]],
        ]))]);

        $client->products()->reorderMedia('55', ['gid://shopify/MediaImage/2', 'gid://shopify/MediaImage/1']);

        $vars = $this->body()['variables'];
        self::assertSame('gid://shopify/Product/55', $vars['id']);
        self::assertSame(
            [
                ['id' => 'gid://shopify/MediaImage/2', 'newPosition' => '0'],
                ['id' => 'gid://shopify/MediaImage/1', 'newPosition' => '1'],
            ],
            $vars['moves'],
        );
    }

    public function testSyncMediaReplacesExistingCreatesNewThenReorders(): void
    {
        $client = $this->mockClient([
            // 1. existing media on the product (slot 1 already present, keyed by alt)
            new Response(200, [], (string) json_encode(['data' => ['product' => ['media' => ['nodes' => [
                ['id' => 'gid://shopify/MediaImage/1', 'alt' => 'ACME_1.jpg', 'mediaContentType' => 'IMAGE'],
            ]]]]])),
            // 2. fileUpdate (replace slot 1 in place)
            new Response(200, [], (string) json_encode(['data' => ['fileUpdate' => [
                'files' => [['id' => 'gid://shopify/MediaImage/1', 'alt' => 'ACME_1.jpg']], 'userErrors' => [],
            ]]])),
            // 3. productCreateMedia (create slot 2)
            new Response(200, [], (string) json_encode(['data' => ['productCreateMedia' => [
                'media' => [['id' => 'gid://shopify/MediaImage/2', 'alt' => 'ACME_2.jpg']], 'mediaUserErrors' => [],
            ]]])),
            // 4. productReorderMedia
            new Response(200, [], (string) json_encode(['data' => ['productReorderMedia' => [
                'job' => ['id' => 'gid://shopify/Job/1', 'done' => false], 'mediaUserErrors' => [],
            ]]])),
        ]);

        $result = $client->products()->syncMedia('55', [
            MediaSlot::url(2, 'https://cdn.example.com/ACME_2.jpg'), // out of order on purpose
            MediaSlot::url(1, 'https://cdn.example.com/ACME_1.jpg'),
        ]);

        self::assertSame(['ACME_1.jpg'], $result['replaced']);
        self::assertSame(['ACME_2.jpg'], $result['created']);
        self::assertSame(['gid://shopify/MediaImage/1', 'gid://shopify/MediaImage/2'], $result['orderedIds']);
        self::assertSame([], $result['userErrors']);
        self::assertCount(4, $this->history);

        // replace (fileUpdate) targets the existing media id with the new source
        $replace = $this->body(1)['variables']['files'][0];
        self::assertSame('gid://shopify/MediaImage/1', $replace['id']);
        self::assertSame('https://cdn.example.com/ACME_1.jpg', $replace['originalSource']);

        // reorder puts slot 1 first (position "0"), slot 2 second
        self::assertSame(
            [
                ['id' => 'gid://shopify/MediaImage/1', 'newPosition' => '0'],
                ['id' => 'gid://shopify/MediaImage/2', 'newPosition' => '1'],
            ],
            $this->body(3)['variables']['moves'],
        );
    }

    public function testSyncMediaBatchesCreatesIntoOneCall(): void
    {
        $client = $this->mockClient([
            new Response(200, [], (string) json_encode(['data' => ['product' => ['media' => ['nodes' => []]]]])),
            // ONE productCreateMedia for both new slots
            new Response(200, [], (string) json_encode(['data' => ['productCreateMedia' => [
                'media' => [
                    ['id' => 'gid://shopify/MediaImage/1', 'alt' => 'A_1.jpg'],
                    ['id' => 'gid://shopify/MediaImage/2', 'alt' => 'A_2.jpg'],
                ],
                'mediaUserErrors' => [],
            ]]])),
            new Response(200, [], (string) json_encode(['data' => ['productReorderMedia' => [
                'job' => ['id' => 'gid://shopify/Job/1', 'done' => false], 'mediaUserErrors' => [],
            ]]])),
        ]);

        $result = $client->products()->syncMedia('55', [
            MediaSlot::url(1, 'https://cdn.example.com/A_1.jpg'),
            MediaSlot::url(2, 'https://cdn.example.com/A_2.jpg'),
        ]);

        self::assertSame(['A_1.jpg', 'A_2.jpg'], $result['created']);
        self::assertSame(['gid://shopify/MediaImage/1', 'gid://shopify/MediaImage/2'], $result['orderedIds']);
        self::assertCount(3, $this->history); // media lookup + ONE createMedia + reorder
        self::assertCount(2, $this->body(1)['variables']['media']); // both creates in a single call
    }

    public function testSyncMediaSingleSlotSkipsReorder(): void
    {
        $client = $this->mockClient([
            new Response(200, [], (string) json_encode(['data' => ['product' => ['media' => ['nodes' => []]]]])),
            new Response(200, [], (string) json_encode(['data' => ['productCreateMedia' => [
                'media' => [['id' => 'gid://shopify/MediaImage/9', 'alt' => 'solo.jpg']], 'mediaUserErrors' => [],
            ]]])),
        ]);

        $result = $client->products()->syncMedia('55', [MediaSlot::url(1, 'https://cdn.example.com/solo.jpg')]);

        self::assertSame(['solo.jpg'], $result['created']);
        self::assertCount(2, $this->history); // media lookup + create, no reorder for a single image
    }
}
