<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\DraftOrderInput;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class DraftOrdersTest extends MockClientTestCase
{
    public function testCreateSendsDraftOrderInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['draftOrderCreate' => ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/1', 'name' => '#D1'], 'userErrors' => []]],
        ]))]);

        $result = $client->draftOrders()->create(DraftOrderInput::make()->customer('5')->lineItem('99', 2));

        self::assertSame('gid://shopify/DraftOrder/1', $result['draftOrder']['id']);
        $input = $this->body()['variables']['input'];
        self::assertSame('gid://shopify/Customer/5', $input['purchasingEntity']['customerId']);
        self::assertSame('gid://shopify/ProductVariant/99', $input['lineItems'][0]['variantId']);
    }

    public function testCompleteReturnsCreatedOrder(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['draftOrderComplete' => ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/1', 'status' => 'COMPLETED', 'order' => ['id' => 'gid://shopify/Order/7']], 'userErrors' => []]],
        ]))]);

        $result = $client->draftOrders()->complete('1', paymentPending: true);

        self::assertSame('gid://shopify/Order/7', $result['draftOrder']['order']['id']);
        $vars = $this->body()['variables'];
        self::assertSame('gid://shopify/DraftOrder/1', $vars['id']);
        self::assertTrue($vars['paymentPending']);
    }

    public function testDeleteReturnsDeletedId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['draftOrderDelete' => ['deletedId' => 'gid://shopify/DraftOrder/1', 'userErrors' => []]],
        ]))]);

        $result = $client->draftOrders()->delete('1');

        self::assertSame('gid://shopify/DraftOrder/1', $result['deletedId']);
        self::assertSame('gid://shopify/DraftOrder/1', $this->body()['variables']['input']['id']);
    }

    public function testGetAcceptsCallerSuppliedSelection(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/1', 'name' => '#D1']],
        ]))]);

        $client->draftOrders()->get('1', fields: 'id name lineItems(first: 5) { nodes { title } }');

        $query = $this->body()['query'];
        self::assertStringContainsString('lineItems(first: 5)', $query);
        self::assertStringNotContainsString('totalPriceSet', $query); // default selection replaced
    }
}
