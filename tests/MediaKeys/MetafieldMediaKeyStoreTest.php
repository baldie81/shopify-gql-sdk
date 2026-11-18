<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\MediaKeys;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Exception\MediaKeyStoreException;
use Baldie81\ShopifyGraphQL\MediaKeys\MetafieldMediaKeyStore;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class MetafieldMediaKeyStoreTest extends MockClientTestCase
{
    private const string PRODUCT = 'gid://shopify/Product/55';

    public function testLoadReadsAndDecodesTheMetafield(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode(['data' => ['product' => [
            'mediaKeys' => ['value' => '{"A_1.jpg":"gid://shopify/MediaImage/1"}'],
        ]]]))]);

        $map = (new MetafieldMediaKeyStore($client->graphql()))->load(self::PRODUCT);

        self::assertSame(['A_1.jpg' => 'gid://shopify/MediaImage/1'], $map);
        self::assertSame(self::PRODUCT, $this->body()['variables']['id']);
    }

    public function testAMissingOrUnreadableMetafieldIsAnEmptyMap(): void
    {
        $store = new MetafieldMediaKeyStore($this->mockClient([])->graphql());

        self::assertSame([], $store->parse(null));
        self::assertSame([], $store->parse(['mediaKeys' => null]));
        self::assertSame([], $store->parse(['mediaKeys' => ['value' => 'not json']]));
        self::assertSame(['a' => 'gid://x'], $store->parse(['mediaKeys' => ['value' => '{"a":"gid://x","b":5,"c":""}']]));
    }

    public function testNamespaceAndKeyAreConfigurable(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode(['data' => ['metafieldsSet' => ['metafields' => [], 'userErrors' => []]]]))]);

        (new MetafieldMediaKeyStore($client->graphql(), 'acme', 'images'))->save(self::PRODUCT, []);

        $input = $this->body()['variables']['metafields'][0];
        self::assertSame('acme', $input['namespace']);
        self::assertSame('images', $input['key']);
        self::assertSame('{}', $input['value']); // an empty map is an object, not a list
    }

    public function testNamespaceThatCouldBreakOutOfTheQueryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MetafieldMediaKeyStore($this->mockClient([])->graphql(), 'x") { value } evil: metafield(namespace: "y');
    }

    public function testSaveSurfacesUserErrors(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode(['data' => ['metafieldsSet' => [
            'metafields' => [], 'userErrors' => [['field' => ['value'], 'message' => 'Value is too long']],
        ]]]))]);

        $this->expectException(MediaKeyStoreException::class);
        $this->expectExceptionMessage('Value is too long');
        (new MetafieldMediaKeyStore($client->graphql()))->save(self::PRODUCT, ['a' => 'gid://x']);
    }

    public function testForgetDeletesTheMetafield(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode(['data' => ['metafieldsDelete' => ['deletedMetafields' => [], 'userErrors' => []]]]))]);

        (new MetafieldMediaKeyStore($client->graphql()))->forget(self::PRODUCT);

        self::assertSame(
            [['ownerId' => self::PRODUCT, 'namespace' => 'shopify_gql_sdk', 'key' => 'media_keys']],
            $this->body()['variables']['metafields'],
        );
    }
}
