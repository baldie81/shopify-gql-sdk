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

    public function testAdjustQuantitiesSendsTheMandatoryChangeFromQuantity(): void
    {
        $response = static fn (): Response => new Response(200, [], (string) json_encode([
            'data' => ['inventoryAdjustQuantities' => ['inventoryAdjustmentGroup' => null, 'userErrors' => []]],
        ]));
        $item = ['inventoryItemId' => 'gid://shopify/InventoryItem/1', 'locationId' => 'gid://shopify/Location/1'];

        // From 2026-04 Shopify rejects a change without the field, null included.
        foreach (['2026-01', '2026-04', '2026-10'] as $version) {
            $client = $this->mockClient([$response()], $version);
            $client->inventory()->adjustQuantities([$item + ['delta' => -5], $item + ['delta' => 2, 'changeFromQuantity' => 7]]);

            $changes = $this->body()['variables']['input']['changes'];
            self::assertArrayHasKey('changeFromQuantity', $changes[0], $version);
            self::assertNull($changes[0]['changeFromQuantity'], $version);
            self::assertSame(7, $changes[1]['changeFromQuantity'], $version); // a caller's guard is kept
        }
    }

    public function testACallersIdempotencyKeyIsSentAsGivenWhenOneCallIsEnough(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['inventoryAdjustQuantities' => ['inventoryAdjustmentGroup' => null, 'userErrors' => []]],
        ]))]);

        $client->inventory()->adjustQuantities(
            [['inventoryItemId' => 'gid://shopify/InventoryItem/1', 'locationId' => 'gid://shopify/Location/1', 'delta' => 1]],
            idempotencyKey: 'my-key',
        );

        self::assertSame('my-key', $this->body()['variables']['idempotencyKey']);
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

    public function testSetQuantitiesSkipsCompareWithExplicitNullOn2026_04(): void
    {
        $client = $this->mockClient([
            new Response(200, [], (string) json_encode(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['reason' => 'correction'], 'userErrors' => []]]])),
        ], '2026-04');

        $client->inventory()->setQuantities([
            ['inventoryItemId' => 'gid://shopify/InventoryItem/1', 'locationId' => 'gid://shopify/Location/1', 'quantity' => 100],
        ]);

        // No pre-read: an explicit null opts out of the compare-and-swap check,
        // so there's no read-then-write race and no extra query.
        self::assertCount(1, $this->history);
        $entry = $this->body(0)['variables']['input']['quantities'][0];
        self::assertArrayHasKey('changeFromQuantity', $entry);
        self::assertNull($entry['changeFromQuantity']);
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

    public function testSetQuantitiesOn2026_01SkipsCompareWithNullAndNoDirective(): void
    {
        // 2026-01 already has `changeFromQuantity` and deprecates `ignoreCompareQuantity`,
        // but doesn't require the @idempotent directive yet.
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['reason' => 'correction'], 'userErrors' => []]],
        ]))], '2026-01');

        $client->inventory()->setQuantities([
            ['inventoryItemId' => 'gid://shopify/InventoryItem/1', 'locationId' => 'gid://shopify/Location/1', 'quantity' => 100],
        ]);

        $body = $this->body();
        self::assertArrayNotHasKey('ignoreCompareQuantity', $body['variables']['input']);
        self::assertArrayHasKey('changeFromQuantity', $body['variables']['input']['quantities'][0]);
        self::assertNull($body['variables']['input']['quantities'][0]['changeFromQuantity']);
        self::assertStringNotContainsString('@idempotent', $body['query']);
        self::assertArrayNotHasKey('idempotencyKey', $body['variables']);
    }
}
