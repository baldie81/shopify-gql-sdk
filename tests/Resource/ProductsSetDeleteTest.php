<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use Baldie81\ShopifyGraphQL\MediaKeys\MetafieldMediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\MediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\JsonFileMediaKeyStore;
use Baldie81\ShopifyGraphQL\MediaKeys\ChainMediaKeyStore;
use Baldie81\ShopifyGraphQL\Exception\MediaKeyStoreException;
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

    public function testSetSelectsWhetherThereAreMoreVariantsThanReturned(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode(['data' => ['productSet' => ['product' => null, 'userErrors' => []]]]))]);

        $client->products()->set(['title' => 'Tee']);

        self::assertStringContainsString('variants(first: 100) { nodes { id sku price } pageInfo { hasNextPage } }', $this->body()['query']);
    }

    public function testDeleteDropsTheMediaKeyMapFromAFileStore(): void
    {
        $dir   = sys_get_temp_dir() . '/sdk-delete-' . bin2hex(random_bytes(6));
        $store = new JsonFileMediaKeyStore($dir);
        $store->save('gid://shopify/Product/55', ['A_1.jpg' => 'gid://shopify/MediaImage/1']);
        $file = $store->path('gid://shopify/Product/55');

        $deleted = new Response(200, [], (string) json_encode([
            'data' => ['productDelete' => ['deletedProductId' => 'gid://shopify/Product/55', 'userErrors' => []]],
        ]));
        $refused = new Response(200, [], (string) json_encode([
            'data' => ['productDelete' => ['deletedProductId' => null, 'userErrors' => [['field' => ['id'], 'message' => 'Product does not exist']]]],
        ]));

        try {
            // Not deleted: the map stays.
            $client = $this->mockClient([$refused]);
            $client->useMediaKeyStore(new ChainMediaKeyStore(new MetafieldMediaKeyStore($client->graphql()), $store));
            $client->products()->delete('55');
            self::assertFileExists($file);

            // Deleted: the file goes; the metafield store needs no request of its own.
            $client = $this->mockClient([$deleted]);
            $client->useMediaKeyStore(new ChainMediaKeyStore(new MetafieldMediaKeyStore($client->graphql()), $store));
            $result = $client->products()->delete('55');

            self::assertSame('gid://shopify/Product/55', $result['deletedProductId']);
            self::assertSame([], $result['userErrors']);
            self::assertFileDoesNotExist($file);
            self::assertCount(1, $this->history);
        } finally {
            @unlink($file);
            @rmdir(dirname($file));
            @rmdir($dir);
        }
    }

    public function testDeleteReportsAKeyStoreThatCouldNotForget(): void
    {
        $store = new class implements MediaKeyStore {
            public function load(string $productGid): array
            {
                return [];
            }

            public function save(string $productGid, array $map): void
            {
            }

            public function forget(string $productGid): void
            {
                throw new MediaKeyStoreException('table locked');
            }
        };
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['productDelete' => ['deletedProductId' => 'gid://shopify/Product/55', 'userErrors' => []]],
        ]))]);
        $client->useMediaKeyStore($store);

        $result = $client->products()->delete('55');

        self::assertSame('gid://shopify/Product/55', $result['deletedProductId']); // the product is gone regardless
        self::assertSame('MEDIA_KEY_STORE_FAILED', $result['userErrors'][0]['code']);
        self::assertSame('table locked', $result['userErrors'][0]['message']);
    }
}
