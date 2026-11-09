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
        ]))], '2025-07'); // pre-2026-04 behaviour

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
        $client = $this->mockClient([new Response(200, [], $ok), new Response(200, [], $ok)], '2025-07'); // pre-2026-04 behaviour

        $quantities = [];
        for ($i = 0; $i < 300; $i++) {
            $quantities[] = ['inventoryItemId' => "gid://shopify/InventoryItem/{$i}", 'locationId' => 'gid://shopify/Location/1', 'quantity' => $i];
        }

        $client->inventory()->setQuantities($quantities);

        self::assertCount(2, $this->history); // 300 -> 250 + 50
        self::assertCount(250, $this->body(0)['variables']['input']['quantities']);
        self::assertCount(50, $this->body(1)['variables']['input']['quantities']);
    }

    public function testSetQuantitiesOn2026_04AddsIdempotentAndDropsIgnoreCompare(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['reason' => 'correction'], 'userErrors' => []]],
        ]))], '2026-04');

        // changeFromQuantity supplied -> no pre-read needed
        $client->inventory()->setQuantities([
            ['inventoryItemId' => 'gid://shopify/InventoryItem/1', 'locationId' => 'gid://shopify/Location/1', 'quantity' => 100, 'changeFromQuantity' => 90],
        ]);

        self::assertCount(1, $this->history);
        $body = $this->body();
        self::assertStringContainsString('@idempotent(key: $idempotencyKey)', $body['query']);
        self::assertNotEmpty($body['variables']['idempotencyKey']);
        self::assertArrayNotHasKey('ignoreCompareQuantity', $body['variables']['input']); // removed in 2026-04
        self::assertSame(90, $body['variables']['input']['quantities'][0]['changeFromQuantity']);
    }

    public function testSetQuantitiesAutoResolvesChangeFromQuantityOn2026_04(): void
    {
        $client = $this->mockClient([
            // 1. pre-read of current quantities
            new Response(200, [], (string) json_encode(['data' => ['nodes' => [
                ['id' => 'gid://shopify/InventoryItem/1', 'inventoryLevels' => ['nodes' => [
                    ['location' => ['id' => 'gid://shopify/Location/1'], 'quantities' => [['name' => 'available', 'quantity' => 5]]],
                ]]],
            ]]])),
            // 2. the set
            new Response(200, [], (string) json_encode(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['reason' => 'correction'], 'userErrors' => []]]])),
        ], '2026-04');

        $client->inventory()->setQuantities([
            ['inventoryItemId' => 'gid://shopify/InventoryItem/1', 'locationId' => 'gid://shopify/Location/1', 'quantity' => 100],
        ]);

        self::assertCount(2, $this->history); // pre-read + set
        self::assertStringContainsString('inventoryLevels', $this->body(0)['query']);
        self::assertSame(5, $this->body(1)['variables']['input']['quantities'][0]['changeFromQuantity']);
    }

    public function testSetQuantitiesChunksUseDistinctIdempotencyKeysOn2026_04(): void
    {
        $ok = (string) json_encode(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['reason' => 'correction'], 'userErrors' => []]]]);
        $client = $this->mockClient([new Response(200, [], $ok), new Response(200, [], $ok)], '2026-04');

        $quantities = [];
        for ($i = 0; $i < 300; $i++) {
            // supply changeFromQuantity so no pre-read; isolates the chunk-key assertion
            $quantities[] = ['inventoryItemId' => "gid://shopify/InventoryItem/{$i}", 'locationId' => 'gid://shopify/Location/1', 'quantity' => $i, 'changeFromQuantity' => 0];
        }
        $client->inventory()->setQuantities($quantities);

        self::assertCount(2, $this->history);
        self::assertNotSame($this->body(0)['variables']['idempotencyKey'], $this->body(1)['variables']['idempotencyKey']); // distinct per chunk
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
