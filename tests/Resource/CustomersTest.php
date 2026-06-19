<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\CustomerInput;
use Baldie81\ShopifyGraphQL\Support\Metafield;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class CustomersTest extends MockClientTestCase
{
    public function testGetReturnsCustomer(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['customer' => ['id' => 'gid://shopify/Customer/5', 'email' => 'jane@example.com', 'legacyResourceId' => '5']],
        ]))]);

        $customer = $client->customers()->get('5');

        self::assertSame('5', $customer['legacyResourceId']);
        self::assertSame('gid://shopify/Customer/5', $this->body()['variables']['id']);
    }

    public function testCreateSendsCustomerInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['customerCreate' => ['customer' => ['id' => 'gid://shopify/Customer/5'], 'userErrors' => []]],
        ]))]);

        $result = $client->customers()->create(CustomerInput::make('jane@example.com')->firstName('Jane'));

        self::assertSame('gid://shopify/Customer/5', $result['customer']['id']);
        $body = $this->body();
        self::assertStringContainsString('customerCreate', $body['query']);
        self::assertSame('jane@example.com', $body['variables']['input']['email']);
    }

    public function testUpdateInjectsId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['customerUpdate' => ['customer' => ['id' => 'gid://shopify/Customer/5'], 'userErrors' => []]],
        ]))]);

        $client->customers()->update('5', CustomerInput::make()->note('VIP'));

        $input = $this->body()['variables']['input'];
        self::assertSame('gid://shopify/Customer/5', $input['id']);
        self::assertSame('VIP', $input['note']);
    }

    public function testSetMetafieldsUsesCustomerOwnerId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['metafieldsSet' => ['metafields' => [['id' => 'gid://shopify/Metafield/1']], 'userErrors' => []]],
        ]))]);

        $client->customers()->setMetafields('5', [Metafield::text('erp', 'customer_id', 'C-1')]);

        self::assertSame('gid://shopify/Customer/5', $this->body()['variables']['metafields'][0]['ownerId']);
    }

    public function testSearchUsesCustomersConnection(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['customers' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]],
        ]))]);

        $client->customers()->search('tag:vip', first: 10);

        $body = $this->body();
        self::assertStringContainsString('customers(', $body['query']);
        self::assertSame('tag:vip', $body['variables']['query']);
    }
}
