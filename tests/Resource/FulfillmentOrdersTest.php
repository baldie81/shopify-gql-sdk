<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class FulfillmentOrdersTest extends MockClientTestCase
{
    public function testForOrderListsFulfillmentOrders(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['order' => ['fulfillmentOrders' => ['nodes' => [['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN']]]]],
        ]))]);

        $list = $client->fulfillmentOrders()->forOrder('7');

        self::assertSame('OPEN', $list[0]['status']);
        self::assertSame('gid://shopify/Order/7', $this->body()['variables']['id']);
    }

    public function testHoldBuildsInputWithTopLevelId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['fulfillmentOrderHold' => ['fulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'ON_HOLD'], 'userErrors' => []]],
        ]))]);

        $client->fulfillmentOrders()->hold('1', 'INVENTORY_OUT_OF_STOCK', reasonNotes: 'awaiting stock', handle: 'app-1');

        $vars = $this->body()['variables'];
        self::assertSame('gid://shopify/FulfillmentOrder/1', $vars['id']); // id is top-level, not in input
        self::assertSame('INVENTORY_OUT_OF_STOCK', $vars['hold']['reason']);
        self::assertSame('awaiting stock', $vars['hold']['reasonNotes']);
        self::assertSame('app-1', $vars['hold']['handle']);
    }

    public function testReleaseHoldPassesHoldIds(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['fulfillmentOrderReleaseHold' => ['fulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN'], 'userErrors' => []]],
        ]))]);

        $client->fulfillmentOrders()->releaseHold('1', ['gid://shopify/FulfillmentHold/9']);

        self::assertSame(['gid://shopify/FulfillmentHold/9'], $this->body()['variables']['holdIds']);
    }

    public function testMoveCoercesLocationGid(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['fulfillmentOrderMove' => ['movedFulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/2'], 'originalFulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/1'], 'userErrors' => []]],
        ]))]);

        $client->fulfillmentOrders()->move('1', '59454423197');

        self::assertSame('gid://shopify/Location/59454423197', $this->body()['variables']['newLocationId']);
    }

    public function testRescheduleSendsFulfillAt(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['fulfillmentOrderReschedule' => ['fulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'SCHEDULED'], 'userErrors' => []]],
        ]))]);

        $client->fulfillmentOrders()->reschedule('1', '2026-07-01T10:00:00Z');

        self::assertSame('2026-07-01T10:00:00Z', $this->body()['variables']['fulfillAt']);
    }

    public function testCancelReturnsReplacement(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['fulfillmentOrderCancel' => ['fulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'CANCELLED'], 'replacementFulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/2'], 'userErrors' => []]],
        ]))]);

        $result = $client->fulfillmentOrders()->cancel('1');

        self::assertSame('gid://shopify/FulfillmentOrder/2', $result['replacementFulfillmentOrder']['id']);
    }

    public function testCloseUsesSharedMessageShape(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['fulfillmentOrderClose' => ['fulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'INCOMPLETE'], 'userErrors' => []]],
        ]))]);

        $client->fulfillmentOrders()->close('1', 'out of stock');

        $body = $this->body();
        self::assertStringContainsString('fulfillmentOrderClose(id: $id, message: $message)', $body['query']);
        self::assertSame('out of stock', $body['variables']['message']);
    }

    public function testSubmitFulfillmentRequest(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['fulfillmentOrderSubmitFulfillmentRequest' => [
                'originalFulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/1'],
                'submittedFulfillmentOrder' => ['id' => 'gid://shopify/FulfillmentOrder/1'],
                'unsubmittedFulfillmentOrder' => null,
                'userErrors' => [],
            ]],
        ]))]);

        $result = $client->fulfillmentOrders()->submitFulfillmentRequest('1', message: 'please ship', notifyCustomer: true);

        self::assertSame('gid://shopify/FulfillmentOrder/1', $result['submittedFulfillmentOrder']['id']);
        self::assertTrue($this->body()['variables']['notifyCustomer']);
    }
}
