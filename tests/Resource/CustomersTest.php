<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\Address;
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

    public function testGetIncludesDefaultAddressButNotFullListByDefault(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['customer' => ['id' => 'gid://shopify/Customer/5']],
        ]))]);

        $client->customers()->get('5');

        $query = $this->body()['query'];
        self::assertStringContainsString('defaultAddress', $query);     // cheap, always
        self::assertStringNotContainsString('addresses(first:', $query); // full list only on request
    }

    public function testGetWithAddressesPullsFullList(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['customer' => ['id' => 'gid://shopify/Customer/5', 'addresses' => [['id' => 'a1', 'city' => 'Austin']]]],
        ]))]);

        $customer = $client->customers()->get('5', withAddresses: true);

        self::assertSame('Austin', $customer['addresses'][0]['city']);
        self::assertStringContainsString('addresses(first:', $this->body()['query']);
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

    public function testAddAddressSendsCustomerAddressCreate(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['customerAddressCreate' => ['address' => ['id' => 'gid://shopify/MailingAddress/1'], 'userErrors' => []]],
        ]))]);

        $result = $client->customers()->addAddress('5', Address::make()->address1('1 A St')->city('Austin')->countryCode('US'), setAsDefault: true);

        self::assertSame('gid://shopify/MailingAddress/1', $result['address']['id']);
        $body = $this->body();
        self::assertStringContainsString('customerAddressCreate', $body['query']);
        self::assertSame('gid://shopify/Customer/5', $body['variables']['customerId']);
        self::assertSame('US', $body['variables']['address']['countryCode']);
        self::assertTrue($body['variables']['setAsDefault']);
    }

    public function testDeleteAddressSendsBothIds(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['customerAddressDelete' => ['deletedAddressId' => 'gid://shopify/MailingAddress/1', 'userErrors' => []]],
        ]))]);

        $result = $client->customers()->deleteAddress('5', 'gid://shopify/MailingAddress/1');

        self::assertSame('gid://shopify/MailingAddress/1', $result['deletedAddressId']);
        $vars = $this->body()['variables'];
        self::assertSame('gid://shopify/Customer/5', $vars['customerId']);
        self::assertSame('gid://shopify/MailingAddress/1', $vars['addressId']);
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
