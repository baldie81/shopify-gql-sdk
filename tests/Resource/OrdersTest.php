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
}
