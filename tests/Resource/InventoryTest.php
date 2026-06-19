<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class InventoryTest extends MockClientTestCase
{
    public function testLocationsReturnsNodes(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1', 'name' => 'Warehouse', 'isActive' => true]]]],
        ]))]);

        self::assertSame('Warehouse', $client->inventory()->locations()[0]['name']);
    }

    public function testSetQuantitiesBuildsInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['reason' => 'correction'], 'userErrors' => []]],
        ]))]);

        $client->inventory()->setQuantities([
            ['inventoryItemId' => 'gid://shopify/InventoryItem/1', 'locationId' => 'gid://shopify/Location/1', 'quantity' => 100],
        ]);

        $input = $this->body()['variables']['input'];
        self::assertSame('available', $input['name']);
        self::assertSame('correction', $input['reason']);
        self::assertTrue($input['ignoreCompareQuantity']);
        self::assertSame(100, $input['quantities'][0]['quantity']);
    }

    public function testAdjustQuantitiesBuildsChanges(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['inventoryAdjustQuantities' => ['inventoryAdjustmentGroup' => ['reason' => 'correction'], 'userErrors' => []]],
        ]))]);

        $client->inventory()->adjustQuantities([
            ['inventoryItemId' => 'gid://shopify/InventoryItem/1', 'locationId' => 'gid://shopify/Location/1', 'delta' => -5],
        ]);

        $body = $this->body();
        self::assertStringContainsString('inventoryAdjustQuantities', $body['query']);
        self::assertSame(-5, $body['variables']['input']['changes'][0]['delta']);
    }

    public function testSetQuantitiesChunksAtTheLimit(): void
    {
        $ok = (string) json_encode(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['reason' => 'correction'], 'userErrors' => []]]]);
        $client = $this->mockClient([new Response(200, [], $ok), new Response(200, [], $ok)]);

        $quantities = [];
        for ($i = 0; $i < 300; $i++) {
            $quantities[] = ['inventoryItemId' => "gid://shopify/InventoryItem/{$i}", 'locationId' => 'gid://shopify/Location/1', 'quantity' => $i];
        }

        $client->inventory()->setQuantities($quantities);

        self::assertCount(2, $this->history); // 300 -> 250 + 50
        self::assertCount(250, $this->body(0)['variables']['input']['quantities']);
        self::assertCount(50, $this->body(1)['variables']['input']['quantities']);
    }

    public function testActivateSendsItemAndLocation(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['inventoryActivate' => ['inventoryLevel' => ['id' => 'gid://shopify/InventoryLevel/1'], 'userErrors' => []]],
        ]))]);

        $client->inventory()->activate('gid://shopify/InventoryItem/1', 'gid://shopify/Location/1', 50);

        $vars = $this->body()['variables'];
        self::assertSame('gid://shopify/InventoryItem/1', $vars['inventoryItemId']);
        self::assertSame('gid://shopify/Location/1', $vars['locationId']);
        self::assertSame(50, $vars['available']);
    }
}
