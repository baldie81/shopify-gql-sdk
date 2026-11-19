<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\Metafield;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class OrdersTest extends MockClientTestCase
{
    public function testGetReturnsOrder(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['order' => ['id' => 'gid://shopify/Order/7', 'name' => '#1007', 'displayFinancialStatus' => 'PAID']],
        ]))]);

        $order = $client->orders()->get('7');

        self::assertSame('#1007', $order['name']);
        self::assertSame('gid://shopify/Order/7', $this->body()['variables']['id']);
    }

    public function testSearchUsesOrdersConnection(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['orders' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]],
        ]))]);

        $client->orders()->search('financial_status:paid');

        self::assertStringContainsString('orders(', $this->body()['query']);
        self::assertSame('financial_status:paid', $this->body()['variables']['query']);
    }

    public function testFulfillFetchesFulfillmentOrdersThenCreates(): void
    {
        $client = $this->mockClient([
            // fulfillment orders lookup
            new Response(200, [], (string) json_encode(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [
                ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN'],
                ['id' => 'gid://shopify/FulfillmentOrder/2', 'status' => 'CLOSED'], // skipped
            ]]]]])),
            // fulfillmentCreate
            new Response(200, [], (string) json_encode(['data' => ['fulfillmentCreate' => [
                'fulfillment' => ['id' => 'gid://shopify/Fulfillment/9', 'status' => 'SUCCESS'], 'userErrors' => [],
            ]]])),
        ]);

        $result = $client->orders()->fulfill('7', ['number' => '1Z999', 'company' => 'UPS'], notifyCustomer: true);

        self::assertSame('SUCCESS', $result['fulfillment']['status']);
        self::assertCount(2, $this->history);

        $fulfillment = $this->body(1)['variables']['fulfillment'];
        self::assertSame([['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/1']], $fulfillment['lineItemsByFulfillmentOrder']); // only OPEN
        self::assertTrue($fulfillment['notifyCustomer']);
        self::assertSame('1Z999', $fulfillment['trackingInfo']['number']);
    }

    public function testFulfillLeavesScheduledFulfillmentOrdersAlone(): void
    {
        $location = ['location' => ['id' => 'gid://shopify/Location/1']];
        $client   = $this->mockClient([
            // a prepaid subscription: this delivery is open, the later ones are scheduled
            new Response(200, [], (string) json_encode(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [
                ['id' => 'gid://shopify/FulfillmentOrder/1', 'status' => 'OPEN', 'assignedLocation' => $location],
                ['id' => 'gid://shopify/FulfillmentOrder/2', 'status' => 'SCHEDULED', 'assignedLocation' => $location],
                ['id' => 'gid://shopify/FulfillmentOrder/3', 'status' => 'ON_HOLD', 'assignedLocation' => $location],
            ]]]]])),
            new Response(200, [], (string) json_encode(['data' => ['fulfillmentCreate' => [
                'fulfillment' => ['id' => 'gid://shopify/Fulfillment/9', 'status' => 'SUCCESS'], 'userErrors' => [],
            ]]])),
        ]);

        $client->orders()->fulfill('7');

        self::assertSame(
            [['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/1']],
            $this->body(1)['variables']['fulfillment']['lineItemsByFulfillmentOrder'],
        );
    }

    public function testFulfillCreatesOneFulfillmentPerAssignedLocation(): void
    {
        // fulfillmentCreate only accepts fulfillment orders assigned to the same location.
        $fo = static fn (int $id, int $location): array => [
            'id' => "gid://shopify/FulfillmentOrder/{$id}", 'status' => 'OPEN',
            'assignedLocation' => ['location' => ['id' => "gid://shopify/Location/{$location}"]],
        ];
        $client = $this->mockClient([
            new Response(200, [], (string) json_encode(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [$fo(1, 10), $fo(2, 20), $fo(3, 10)]]]]])),
            new Response(200, [], (string) json_encode(['data' => ['fulfillmentCreate' => [
                'fulfillment' => ['id' => 'gid://shopify/Fulfillment/1', 'status' => 'SUCCESS'], 'userErrors' => [],
            ]]])),
            new Response(200, [], (string) json_encode(['data' => ['fulfillmentCreate' => [
                'fulfillment' => null, 'userErrors' => [['field' => ['fulfillment'], 'message' => 'Location is inactive']],
            ]]])),
        ]);

        $result = $client->orders()->fulfill('7', ['number' => 'T1']);

        self::assertStringContainsString('assignedLocation { location { id } }', $this->body(0)['query']);
        self::assertSame(
            [['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/1'], ['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/3']],
            $this->body(1)['variables']['fulfillment']['lineItemsByFulfillmentOrder'],
        );
        self::assertSame(
            [['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/2']],
            $this->body(2)['variables']['fulfillment']['lineItemsByFulfillmentOrder'],
        );
        self::assertSame('T1', $this->body(2)['variables']['fulfillment']['trackingInfo']['number']);

        // The first location went through; the second one's error is reported alongside it.
        self::assertSame('gid://shopify/Fulfillment/1', $result['fulfillment']['id']);
        self::assertCount(1, $result['fulfillments']);
        self::assertSame('Location is inactive', $result['userErrors'][0]['message']);
    }

    public function testFulfillWithNothingFulfillableSkipsMutation(): void
    {
        $client = $this->mockClient([
            new Response(200, [], (string) json_encode(['data' => ['order' => ['fulfillmentOrders' => ['nodes' => [
                ['id' => 'gid://shopify/FulfillmentOrder/2', 'status' => 'CLOSED'],
            ]]]]])),
        ]);

        $result = $client->orders()->fulfill('7');

        self::assertNull($result['fulfillment']);
        self::assertCount(1, $this->history); // no fulfillmentCreate
    }

    public function testRefundInjectsOrderId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['refundCreate' => ['refund' => ['id' => 'gid://shopify/Refund/3'], 'order' => ['id' => 'gid://shopify/Order/7'], 'userErrors' => []]],
        ]))]);

        $client->orders()->refund('7', ['note' => 'Damaged', 'refundLineItems' => [['lineItemId' => 'gid://shopify/LineItem/1', 'quantity' => 1]]]);

        $input = $this->body()['variables']['input'];
        self::assertSame('gid://shopify/Order/7', $input['orderId']);
        self::assertSame('Damaged', $input['note']);
    }

    public function testRefundOn2026_04AddsIdempotentDirective(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['refundCreate' => ['refund' => ['id' => 'gid://shopify/Refund/3'], 'order' => ['id' => 'gid://shopify/Order/7'], 'userErrors' => []]],
        ]))], '2026-04');

        $client->orders()->refund('7', ['note' => 'x']);

        $body = $this->body();
        self::assertStringContainsString('refundCreate(input: $input) @idempotent(key: $idempotencyKey)', $body['query']);
        self::assertNotEmpty($body['variables']['idempotencyKey']);
    }

    public function testRefundOnOlderVersionHasNoIdempotentDirective(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['refundCreate' => ['refund' => ['id' => 'gid://shopify/Refund/3'], 'order' => null, 'userErrors' => []]],
        ]))], '2025-07'); // pre-idempotency version

        $client->orders()->refund('7', ['note' => 'x']);

        $body = $this->body();
        self::assertStringNotContainsString('@idempotent', $body['query']);
        self::assertArrayNotHasKey('idempotencyKey', $body['variables']);
    }

    public function testSetMetafieldsUsesOrderOwnerId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldsSet' => ['metafields' => [['id' => 'gid://shopify/Metafield/1']], 'userErrors' => []]],
        ]))]);

        // the ERP write-back use case
        $client->orders()->setMetafields('7', [
            Metafield::text('erp', 'order_id', 'SO-1001'),
            Metafield::boolean('erp', 'synced', true),
            Metafield::text('erp', 'message', 'OK'),
        ]);

        $sent = $this->body()['variables']['metafields'];
        self::assertSame('gid://shopify/Order/7', $sent[0]['ownerId']);
        self::assertSame('synced', $sent[1]['key']);
        self::assertSame('true', $sent[1]['value']);
    }

    public function testDefaultSelectionsLeaveOutProtectedCustomerFields(): void
    {
        // An app without approval for customer email would get an `errors`
        // entry for it, turning every default read into an exception.
        $empty  = static fn (string $root): Response => new Response(200, [], (string) json_encode(['data' => [$root => null]]));
        $client = $this->mockClient([$empty('order'), $empty('draftOrder'), $empty('giftCard')]);

        $client->orders()->get('1');
        $client->draftOrders()->get('1');
        $client->giftCards()->get('1');

        foreach ([0, 1, 2] as $index) {
            self::assertStringContainsString('customer { id }', $this->body($index)['query']);
            self::assertStringNotContainsString('email', $this->body($index)['query']);
        }
    }
}
