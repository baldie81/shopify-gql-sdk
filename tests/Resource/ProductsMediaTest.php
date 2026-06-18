<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\Media;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class ProductsMediaTest extends MockClientTestCase
{
    public function testAddMediaSendsCreateMediaInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productCreateMedia' => [
                'media'           => [['id' => 'gid://shopify/MediaImage/1', 'alt' => 'front.jpg']],
                'mediaUserErrors' => [],
            ]],
        ]))]);

        $client->products()->addMedia('55', [Media::image('https://cdn.example.com/front.jpg')]);

        $body = $this->body();
        self::assertStringContainsString('CreateMediaInput!', $body['query']);
        self::assertSame('gid://shopify/Product/55', $body['variables']['productId']);
        $media = $body['variables']['media'][0];
        self::assertSame('https://cdn.example.com/front.jpg', $media['originalSource']);
        self::assertSame('IMAGE', $media['mediaContentType']);
        self::assertSame('front.jpg', $media['alt']);
    }

    public function testEnsureMediaSkipsExistingAndAddsTheRest(): void
    {
        $client = $this->mockClient([
            // existing media on the product
            new Response(200, [], (string) json_encode(['data' => ['product' => ['media' => ['nodes' => [
                ['id' => 'gid://shopify/MediaImage/1', 'alt' => 'front.jpg', 'mediaContentType' => 'IMAGE'],
            ]]]]])),
            // productCreateMedia for the missing one
            new Response(200, [], (string) json_encode(['data' => ['productCreateMedia' => [
                'media'           => [['id' => 'gid://shopify/MediaImage/2', 'alt' => 'back.jpg']],
                'mediaUserErrors' => [],
            ]]])),
        ]);

        $result = $client->products()->ensureMedia('55', [
            Media::image('https://cdn.example.com/front.jpg'),  // already present
            Media::image('https://cdn.example.com/back.jpg'),   // new
        ]);

        self::assertSame(['front.jpg'], $result['skipped']);
        self::assertCount(1, $result['added']);
        self::assertCount(2, $this->history);

        // only the new media is sent to productCreateMedia
        $created = $this->body(1)['variables']['media'];
        self::assertCount(1, $created);
        self::assertSame('back.jpg', $created[0]['alt']);
    }

    public function testEnsureMediaMakesNoMutationWhenAllPresent(): void
    {
        $client = $this->mockClient([
            new Response(200, [], (string) json_encode(['data' => ['product' => ['media' => ['nodes' => [
                ['id' => '1', 'alt' => 'front.jpg'],
                ['id' => '2', 'alt' => 'back.jpg'],
            ]]]]])),
        ]);

        $result = $client->products()->ensureMedia('55', [
            Media::image('https://cdn.example.com/front.jpg'),
            Media::image('https://cdn.example.com/back.jpg'),
        ]);

        self::assertSame([], $result['added']);
        self::assertSame(['front.jpg', 'back.jpg'], $result['skipped']);
        self::assertCount(1, $this->history); // only the media() lookup, no mutation
    }

    public function testEnsureMediaDedupesWithinBatch(): void
    {
        $client = $this->mockClient([
            new Response(200, [], (string) json_encode(['data' => ['product' => ['media' => ['nodes' => []]]]])),
            new Response(200, [], (string) json_encode(['data' => ['productCreateMedia' => [
                'media' => [['id' => '9', 'alt' => 'dup.jpg']], 'mediaUserErrors' => [],
            ]]])),
        ]);

        $client->products()->ensureMedia('55', [
            Media::image('https://a.example.com/dup.jpg'),
            Media::image('https://b.example.com/dup.jpg'), // same key 'dup.jpg'
        ]);

        self::assertCount(1, $this->body(1)['variables']['media']);
    }
}
