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
        $client = $this->mockClient([
            // media already on the product (so the new node can be told apart)
            new Response(200, [], (string) json_encode(['data' => ['product' => ['media' => ['nodes' => [
                ['id' => 'gid://shopify/MediaImage/0', 'alt' => 'old.jpg'],
            ]]]]])),
            // productUpdate returns the product's media, newest first
            new Response(200, [], (string) json_encode(['data' => ['productUpdate' => [
                'product'    => ['id' => 'gid://shopify/Product/55', 'media' => ['nodes' => [
                    ['id' => 'gid://shopify/MediaImage/1', 'alt' => 'front.jpg'],
                ]]],
                'userErrors' => [],
            ]]])),
        ]);

        $result = $client->products()->addMedia('55', [Media::image('https://cdn.example.com/front.jpg')]);

        // only the node that wasn't there before is reported as created
        self::assertSame([['id' => 'gid://shopify/MediaImage/1', 'alt' => 'front.jpg']], $result['media']);
        self::assertSame([], $result['userErrors']);
        self::assertSame([], $result['mediaUserErrors']); // backward-compatible alias

        $body = $this->body();
        self::assertStringContainsString('productUpdate(product: $product, media: $media)', $body['query']);
        self::assertStringNotContainsString('productCreateMedia', $body['query']); // deprecated
        self::assertSame('gid://shopify/Product/55', $body['variables']['product']['id']);
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
            // productUpdate for the missing one (returns media newest first)
            new Response(200, [], (string) json_encode(['data' => ['productUpdate' => [
                'product'    => ['id' => 'gid://shopify/Product/55', 'media' => ['nodes' => [
                    ['id' => 'gid://shopify/MediaImage/2', 'alt' => 'back.jpg'],
                ]]],
                'userErrors' => [],
            ]]])),
        ]);

        $result = $client->products()->ensureMedia('55', [
            Media::image('https://cdn.example.com/front.jpg'),  // already present
            Media::image('https://cdn.example.com/back.jpg'),   // new
        ]);

        self::assertSame(['front.jpg'], $result['skipped']);
        self::assertCount(1, $result['added']);
        self::assertCount(2, $this->history);

        self::assertSame('gid://shopify/MediaImage/2', $result['added'][0]['id']);

        // only the new media is sent
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
