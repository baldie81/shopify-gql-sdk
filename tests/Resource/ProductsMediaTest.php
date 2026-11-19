<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use Baldie81\ShopifyGraphQL\Support\MediaEntry;
use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\Media;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class ProductsMediaTest extends MockClientTestCase
{
    public function testAddMediaSendsCreateMediaInput(): void
    {
        $client = $this->altOnlyClient([
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
        $client = $this->altOnlyClient([
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
        $client = $this->altOnlyClient([
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
        $client = $this->altOnlyClient([
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

    public function testAddMediaSendsMoreThanAPageOfInputsInSeveralCalls(): void
    {
        $node  = static fn (int $i): array => ['id' => "gid://shopify/MediaImage/{$i}", 'alt' => "a{$i}"];
        $reply = static fn (array $nodes): Response => new Response(200, [], (string) json_encode(['data' => ['productUpdate' => [
            'product' => ['id' => 'gid://shopify/Product/55', 'media' => ['nodes' => $nodes]], 'userErrors' => [],
        ]]]));

        $client = $this->altOnlyClient([
            new Response(200, [], (string) json_encode(['data' => ['product' => ['media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]]]])),
            $reply(array_map($node, range(250, 1))),              // newest first
            $reply([$node(251), $node(250)]),                    // one new, one already counted
        ]);

        $media = [];
        for ($i = 1; $i <= 251; $i++) {
            $media[] = Media::image("https://cdn.test/{$i}.jpg");
        }
        $result = $client->products()->addMedia('55', $media);

        self::assertCount(3, $this->history);
        self::assertCount(250, $this->body(1)['variables']['media']);
        self::assertSame(250, $this->body(1)['variables']['first']);
        self::assertCount(1, $this->body(2)['variables']['media']);
        self::assertSame(1, $this->body(2)['variables']['first']);

        // All 251, in input order, none twice.
        self::assertSame(array_map(static fn (int $i): string => "gid://shopify/MediaImage/{$i}", range(1, 251)), array_column($result['media'], 'id'));
    }

    public function testSyncMediaRejectsTwoSlotsWithTheSameKeyBeforeDoingAnything(): void
    {
        $client = $this->altOnlyClient([]);

        try {
            $client->products()->syncMedia('55', [
                MediaEntry::url(1, 'https://cdn.test/a/FRONT.jpg'),
                MediaEntry::url(2, 'https://cdn.test/b/FRONT.jpg'),
                MediaEntry::url(3, 'https://cdn.test/BACK.jpg'),
            ]);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('FRONT.jpg', $e->getMessage());
            self::assertStringNotContainsString('BACK.jpg', $e->getMessage());
            self::assertCount(0, $this->history);
        }
    }
}
