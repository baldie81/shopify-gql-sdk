<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\CollectionInput;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class CollectionsTest extends MockClientTestCase
{
    public function testCreateManualCollection(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/9', 'title' => 'Summer'], 'userErrors' => []]],
        ]))]);

        $result = $client->collections()->create(CollectionInput::make('Summer')->descriptionHtml('<p>hot</p>'));

        self::assertSame('gid://shopify/Collection/9', $result['collection']['id']);
        $body = $this->body();
        self::assertStringContainsString('collectionCreate', $body['query']);
        self::assertSame('Summer', $body['variables']['input']['title']);
        self::assertArrayNotHasKey('ruleSet', $body['variables']['input']); // manual -> no ruleSet
    }

    public function testCreateSmartCollectionBuildsRuleSet(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/9'], 'userErrors' => []]],
        ]))]);

        $client->collections()->create(
            CollectionInput::make('On sale')->disjunctive(false)->rule('TAG', 'EQUALS', 'sale')
        );

        $ruleSet = $this->body()['variables']['input']['ruleSet'];
        self::assertFalse($ruleSet['appliedDisjunctively']);
        self::assertSame(['column' => 'TAG', 'relation' => 'EQUALS', 'condition' => 'sale'], $ruleSet['rules'][0]);
    }

    public function testDeleteReturnsDeletedId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['collectionDelete' => ['deletedCollectionId' => 'gid://shopify/Collection/9', 'userErrors' => []]],
        ]))]);

        $result = $client->collections()->delete('9');

        self::assertSame('gid://shopify/Collection/9', $result['deletedCollectionId']);
        self::assertSame('gid://shopify/Collection/9', $this->body()['variables']['input']['id']);
    }

    public function testAddProductsIsSynchronousAndCoercesGids(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['collectionAddProducts' => ['collection' => ['id' => 'gid://shopify/Collection/9'], 'userErrors' => []]],
        ]))]);

        $result = $client->collections()->addProducts('9', ['111', 'gid://shopify/Product/222']);

        self::assertArrayHasKey('collection', $result);
        self::assertSame(['gid://shopify/Product/111', 'gid://shopify/Product/222'], $this->body()['variables']['productIds']);
    }

    public function testRemoveProductsReturnsJob(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['collectionRemoveProducts' => ['job' => ['id' => 'gid://shopify/Job/1', 'done' => false], 'userErrors' => []]],
        ]))]);

        $result = $client->collections()->removeProducts('9', ['gid://shopify/Product/1']);

        self::assertFalse($result['job']['done']);
        self::assertStringContainsString('collectionRemoveProducts', $this->body()['query']);
    }
}
